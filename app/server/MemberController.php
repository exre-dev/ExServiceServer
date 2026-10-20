<?php

namespace app\server;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\ServerMemberModel;
use app\common\model\UserModel;
use app\common\services\AuditService;

use support\Request;
use support\Response;

/**
 * 空间管理端-成员管理（无邀请/申请模式, 管理员检索用户库直接添加）
 */
class MemberController
{
  /**
   * 成员列表
   */
  #[OA\Get(
    path: '/api/server/{sid}/members',
    operationId: 'serverMemberList',
    summary: '成员列表',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。包含 active/banned/left 全部成员，按 ID 倒序，user 仅选取 id/email/username/avatar/qq。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/ServerMembersData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function index(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $list = ServerMemberModel::where('server_id', $sid)
      ->with('user:id,email,username,avatar,qq')
      ->orderByDesc('id')
      ->get();

    return success([
      'list' => $list,
      'my_role' => (string) $request->server_role,
    ]);
  }

  /**
   * 添加成员（按邮箱或 QQ 精确匹配用户库; 已离开成员重新激活）
   */
  #[OA\Post(
    path: '/api/server/{sid}/members',
    operationId: 'serverMemberAdd',
    summary: '添加或重新激活成员',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。keyword 精确匹配邮箱或 QQ；拒绝平台封禁用户、现有 owner 或 banned 成员；仅 owner 可授予 admin。已离开成员重新激活。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\JsonContent(ref: '#/components/schemas/ServerMemberAddInput'),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ServerMemberAddInput',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function add(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $keyword = trim((string) $request->post('keyword', ''));
    $role = (string) $request->post('role', 'member');
    if ($keyword === '' || !in_array($role, ['admin', 'member'], true)) {
      throw new ApiException(
        'keyword 必填, role 为 admin/member（owner 转让暂未开放）',
      );
    }

    $user = UserModel::where('email', $keyword)
      ->orWhere('qq', $keyword)
      ->first();
    if (!$user) {
      throw new ApiException('未找到匹配的用户（邮箱/QQ）');
    }
    if ((int) $user->ban !== 0) {
      throw new ApiException('该用户已被平台封禁');
    }

    // admin 角色仅 owner 可授予
    if ($role === 'admin' && $request->server_role !== 'owner') {
      throw new ApiException('仅 Owner 可添加管理员');
    }

    $existing = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $user->id)
      ->first();
    if ($existing) {
      if ($existing->role_key === 'owner') {
        throw new ApiException('该用户是空间 Owner');
      }
      if ($existing->status === 'banned') {
        throw new ApiException('该成员已被禁用, 请先解除禁用');
      }
      // 重新加入/调整
      $existing->status = 'active';
      $existing->role_key = $role;
      $existing->joined_time = date('Y-m-d H:i:s');
      $existing->save();
    } else {
      $member = new ServerMemberModel();
      $member->server_id = $sid;
      $member->user_id = $user->id;
      $member->role_key = $role;
      $member->status = 'active';
      $member->source = 'manual';
      $member->joined_time = date('Y-m-d H:i:s');
      $member->save();
    }

    AuditService::log((int) $request->uid, 'server', 'member.add', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $user->id,
      'detail' => ['role' => $role, 'keyword' => $keyword],
    ]);
    return success([], '成员已添加');
  }

  /**
   * 调整成员角色（仅 Owner）
   */
  #[OA\Post(
    path: '/api/server/{sid}/members/{uid}/role',
    operationId: 'serverMemberRole',
    summary: '调整成员角色',
    description: '需要当前空间 active 成员身份及 owner 角色；权限或空间不存在按当前实现返回 400。不能调整 owner；目标用户按当前空间查找。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'uid',
        in: 'path',
        required: true,
        description: '目标用户 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\JsonContent(ref: '#/components/schemas/ServerMemberRoleInput'),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ServerMemberRoleInput',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function role(Request $request, int $sid, int $uid): Response
  {
    ServerMember::assertOwner($request);

    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在');
    }
    if ($member->role_key === 'owner') {
      throw new ApiException('不能调整 Owner 角色');
    }

    $role = (string) $request->post('role', '');
    if (!in_array($role, ['admin', 'member'], true)) {
      throw new ApiException('role 为 admin/member');
    }
    $member->role_key = $role;
    $member->save();

    AuditService::log((int) $request->uid, 'server', 'member.role', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
      'detail' => ['role' => $role],
    ]);
    return success([], '角色已调整');
  }

  /**
   * 移除成员（软离开 left）
   */
  #[OA\Post(
    path: '/api/server/{sid}/members/{uid}/remove',
    operationId: 'serverMemberRemove',
    summary: '移除成员',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。状态改为 left。不能操作自己或 owner，admin 只能操作 member。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'uid',
        in: 'path',
        required: true,
        description: '目标用户 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function remove(Request $request, int $sid, int $uid): Response
  {
    ServerMember::assertManager($request);

    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在');
    }
    $this->assertCanActOn($request, $member, $uid);
    $member->status = 'left';
    $member->save();

    AuditService::log((int) $request->uid, 'server', 'member.remove', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
    ]);
    return success([], '成员已移除');
  }

  /**
   * 禁用成员（banned, 游戏权限判定按非成员处理）
   */
  #[OA\Post(
    path: '/api/server/{sid}/members/{uid}/ban',
    operationId: 'serverMemberBan',
    summary: '禁用成员',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。状态改为 banned，权限判定按非成员处理。不能操作自己或 owner，admin 只能操作 member。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'uid',
        in: 'path',
        required: true,
        description: '目标用户 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function ban(Request $request, int $sid, int $uid): Response
  {
    ServerMember::assertManager($request);

    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在');
    }
    $this->assertCanActOn($request, $member, $uid);
    $member->status = 'banned';
    $member->save();

    AuditService::log((int) $request->uid, 'server', 'member.ban', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
    ]);
    return success([], '成员已禁用');
  }

  /**
   * 解除禁用
   */
  #[OA\Post(
    path: '/api/server/{sid}/members/{uid}/unban',
    operationId: 'serverMemberUnban',
    summary: '解除成员禁用',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。目标必须为 banned 且非 owner。当前动作没有禁止操作自己/admin 的额外检查。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'uid',
        in: 'path',
        required: true,
        description: '目标用户 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function unban(Request $request, int $sid, int $uid): Response
  {
    ServerMember::assertManager($request);

    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在');
    }
    if ($member->role_key === 'owner') {
      throw new ApiException('不能对 Owner 执行该操作');
    }
    if ($member->status !== 'banned') {
      throw new ApiException('该成员未被禁用');
    }
    $member->status = 'active';
    $member->save();

    AuditService::log((int) $request->uid, 'server', 'member.unban', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
    ]);
    return success([], '已解除禁用');
  }

  /**
   * 操作保护: 不能动自己 / admin 只能动 member, owner/admin 中的 admin 只能由 owner 动
   */
  private function assertCanActOn(Request $request, $member, int $uid): void
  {
    if ($uid === (int) $request->uid) {
      throw new ApiException('不能对自己执行该操作');
    }
    if ($member->role_key === 'owner') {
      throw new ApiException('不能对 Owner 执行该操作');
    }
    if ($member->role_key === 'admin' && $request->server_role !== 'owner') {
      throw new ApiException('仅 Owner 可操作管理员');
    }
  }
}
