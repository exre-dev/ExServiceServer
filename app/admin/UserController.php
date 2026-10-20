<?php

namespace app\admin;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\model\AuditLogModel;
use app\common\model\AuthSessionModel;
use app\common\model\ServerMemberModel;
use app\common\model\UserModel;
use app\common\services\AuditService;
use app\common\services\SessionService;

use support\Request;
use support\Response;

/**
 * 平台管理端-用户管理（C 端账号）
 */
class UserController
{
  /**
   * 用户列表（搜索/筛选/分页）
   */
  #[OA\Get(
    path: '/api/admin/users',
    operationId: 'adminUserIndex',
    summary: '用户列表',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'page',
        in: 'query',
        required: false,
        description: '页码，小于1按1处理',
        schema: new OA\Schema(type: 'integer', minimum: 1, default: 1),
      ),
      new OA\Parameter(
        name: 'page_size',
        in: 'query',
        required: false,
        description: '每页数量，服务端裁剪到允许范围',
        schema: new OA\Schema(
          type: 'integer',
          minimum: 1,
          maximum: 50,
          default: 10,
        ),
      ),
      new OA\Parameter(
        name: 'keyword',
        in: 'query',
        required: false,
        description: '关键词，去除首尾空白后模糊搜索',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'ban',
        in: 'query',
        required: false,
        description: '非空时按整数封禁标记筛选（0正常，1封禁）',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功',
        content: new OA\JsonContent(
          type: 'object',
          properties: [
            new OA\Property(
              property: 'msg',
              type: 'string',
              description: '成功消息',
            ),
            new OA\Property(
              property: 'data',
              type: 'object',
              properties: [
                new OA\Property(
                  property: 'list',
                  type: 'array',
                  items: new OA\Items(ref: '#/components/schemas/AdminUser'),
                ),
                new OA\Property(property: 'total', type: 'integer'),
              ],
            ),
          ],
          required: ['msg', 'data'],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function index(Request $request): Response
  {
    $page = max(1, (int) $request->get('page', 1));
    $pageSize = min(50, max(1, (int) $request->get('page_size', 10)));
    $keyword = trim((string) $request->get('keyword', ''));
    $ban = $request->get('ban', '');

    $q = UserModel::query();
    if ($keyword !== '') {
      $q->where(function ($sq) use ($keyword) {
        $sq
          ->where('email', 'like', "%{$keyword}%")
          ->orWhere('username', 'like', "%{$keyword}%")
          ->orWhere('qq', 'like', "%{$keyword}%");
      });
    }
    if ($ban !== '' && $ban !== null) {
      $q->where('ban', (int) $ban);
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    return success([
      'list' => $pager->items(),
      'total' => $pager->total(),
    ]);
  }

  /**
   * 用户详情（信息 + 会话设备 + 加入的空间）
   */
  #[OA\Get(
    path: '/api/admin/users/{id}',
    operationId: 'adminUserDetail',
    summary: '用户详情',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '记录 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功',
        content: new OA\JsonContent(
          type: 'object',
          properties: [
            new OA\Property(
              property: 'msg',
              type: 'string',
              description: '成功消息',
            ),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/AdminUserDetail',
            ),
          ],
          required: ['msg', 'data'],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function detail(Request $request, int $id): Response
  {
    $user = UserModel::find($id);
    if (!$user) {
      throw new ApiException('用户不存在');
    }

    $sessions = AuthSessionModel::where('user_id', $id)
      ->orderByDesc('id')
      ->limit(50)
      ->get();

    $servers = ServerMemberModel::where('user_id', $id)
      ->with('server:id,name,slug,status')
      ->get();

    $logs = AuditLogModel::where('actor_user_id', $id)
      ->orderByDesc('id')
      ->limit(20)
      ->get();

    $tags = \app\common\model\UserTagModel::where('user_id', $id)
      ->with('tag:id,name')
      ->get()
      ->map(fn($r) => ['id' => (int) $r->tag_id, 'name' => $r->tag?->name])
      ->values();

    return success([
      'user' => $user,
      'sessions' => $sessions,
      'servers' => $servers,
      'tags' => $tags,
      'recent_logs' => $logs,
    ]);
  }

  /**
   * 封禁/解封（封禁即吊销全部会话）
   */
  #[OA\Post(
    path: '/api/admin/users/{id}/ban',
    operationId: 'adminUserBan',
    summary: '封禁或解封用户',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。不能封禁自己；操作超级管理员目标仅限超级管理员。封禁立即撤销该用户全部会话；拒绝分支返回400。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '记录 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: false,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(
            property: 'ban',
            type: 'integer',
            default: 1,
            description: '整数转换后等于1为封禁，其他值为解封；省略默认封禁',
          ),
        ],
      ),
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功',
        content: new OA\JsonContent(
          type: 'object',
          properties: [
            new OA\Property(
              property: 'msg',
              type: 'string',
              description: '成功消息',
            ),
            new OA\Property(
              property: 'data',
              type: 'array',
              items: new OA\Items(type: 'string'),
              maxItems: 0,
              description: '当前成功响应为空数组 []',
            ),
          ],
          required: ['msg', 'data'],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function ban(Request $request, int $id): Response
  {
    $user = UserModel::find($id);
    if (!$user) {
      throw new ApiException('用户不存在');
    }
    if ($id === (int) $request->uid) {
      throw new ApiException('不能封禁自己');
    }
    // 超管账号只有超管能动
    if (
      (string) $user->platform_role === 'superadmin' &&
      $request->platform_role !== 'superadmin'
    ) {
      throw new ApiException('仅超级管理员可操作超管账号');
    }

    $ban = (int) $request->post('ban', 1) === 1 ? 1 : 0;
    $user->ban = $ban;
    $user->save();

    if ($ban === 1) {
      SessionService::revokeAllForUser($id);
    }

    AuditService::log((int) $request->uid, 'platform', 'user.ban', [
      'target_type' => 'user',
      'target_id' => $id,
      'detail' => ['ban' => $ban],
    ]);
    return success([], $ban ? '已封禁并下线全部会话' : '已解封');
  }

  /**
   * 设置平台角色（''/ops/superadmin; superadmin 目标仅超管可操作）
   */
  #[OA\Post(
    path: '/api/admin/users/{id}/platform-role',
    operationId: 'adminUserPlatformRole',
    summary: '设置平台角色',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。授予 superadmin 仅超级管理员可操作，拒绝分支返回400；空角色清除平台权限。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '记录 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: false,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(
            property: 'role',
            type: 'string',
            enum: ['', 'ops', 'superadmin'],
            default: '',
            description: '省略即清除角色；授予 superadmin 仅超级管理员可操作',
          ),
        ],
      ),
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功',
        content: new OA\JsonContent(
          type: 'object',
          properties: [
            new OA\Property(
              property: 'msg',
              type: 'string',
              description: '成功消息',
            ),
            new OA\Property(
              property: 'data',
              type: 'array',
              items: new OA\Items(type: 'string'),
              maxItems: 0,
              description: '当前成功响应为空数组 []',
            ),
          ],
          required: ['msg', 'data'],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function platform_role(Request $request, int $id): Response
  {
    $user = UserModel::find($id);
    if (!$user) {
      throw new ApiException('用户不存在');
    }

    $role = (string) $request->post('role', '');
    if (!in_array($role, ['', 'ops', 'superadmin'], true)) {
      throw new ApiException('非法角色值');
    }
    if ($role === 'superadmin' && $request->platform_role !== 'superadmin') {
      throw new ApiException('仅超级管理员可授予超管角色');
    }

    $user->platform_role = $role;
    $user->save();

    AuditService::log((int) $request->uid, 'platform', 'user.platform_role', [
      'target_type' => 'user',
      'target_id' => $id,
      'detail' => ['role' => $role],
    ]);
    return success([], '平台角色已更新');
  }
}
