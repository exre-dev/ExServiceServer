<?php

namespace app\server;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\ServerMemberModel;
use app\common\model\UserIdentModel;
use app\common\model\UserModel;
use app\common\services\AuditService;

use support\Redis;
use support\Request;
use support\Response;

/**
 * 空间管理端-游戏绑定（玩家游戏内 /exbind 生成 6 位 ticket, 管理员录入完成绑定）
 */
class IdentController
{
  /** Redis ticket 键前缀（与蓝图 §6 约定一致, /open 生成端在 P3 实现） */
  private const TICKET_PREFIX = 'bind:tkt:';

  /**
   * 本空间绑定记录列表
   */
  #[OA\Get(
    path: '/api/server/{sid}/idents',
    operationId: 'serverIdentList',
    summary: '游戏绑定记录',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅返回 bound_server_id 为当前空间的记录，按 ID 倒序；列表返回完整 ident_value 与精简 user 关系。',
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
              ref: '#/components/schemas/ServerIdentsData',
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

    $list = UserIdentModel::where('bound_server_id', $sid)
      ->with('user:id,email,username,avatar')
      ->orderByDesc('id')
      ->get();

    return success(['list' => $list]);
  }

  /**
   * 录入绑定码: 校验 ticket → 绑定游戏标识到指定用户 → 自动入空间为 member
   * ticket 一次性（Redis get + del）, 300 秒有效, 与空间强校验
   */
  #[OA\Post(
    path: '/api/server/{sid}/idents/bind',
    operationId: 'serverIdentBind',
    summary: '使用游戏 ticket 绑定用户',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。ticket 校验归属空间并一次性消费；目标用户须存在，游戏标识全局唯一。已有同用户绑定直接返回空 data 和提示；其他用户绑定或空间 banned 拒绝。无成员关系则自动加入，left 成员重新激活。新绑定仅回显 ident_tail 与用户 ID/昵称。',
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
        new OA\JsonContent(ref: '#/components/schemas/ServerIdentBindInput'),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ServerIdentBindInput',
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
              oneOf: [
                new OA\Schema(ref: '#/components/schemas/ServerIdentBoundData'),
                new OA\Schema(
                  type: 'array',
                  maxItems: 0,
                  items: new OA\Items(type: 'string'),
                ),
              ],
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
  public function bind(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $ticket = trim((string) $request->post('ticket', ''));
    $uid = (int) $request->post('user_id', 0);
    if ($ticket === '' || $uid <= 0) {
      throw new ApiException('ticket 与 user_id 必填');
    }

    // 一次性取出
    $key = self::TICKET_PREFIX . $ticket;
    $raw = Redis::get($key);
    if (!$raw) {
      throw new ApiException('绑定码不存在或已过期, 请玩家在游戏内重新生成');
    }
    Redis::del($key);

    $tkt = json_decode($raw, true);
    if (!is_array($tkt) || (int) ($tkt['server_id'] ?? 0) !== $sid) {
      throw new ApiException('绑定码不属于当前空间');
    }
    $ident = (string) ($tkt['ident'] ?? '');
    $identType = (string) ($tkt['ident_type'] ?? 'fivem_license');
    if ($ident === '') {
      throw new ApiException('绑定码数据异常');
    }

    // 目标用户必须是空间成员（或自动加入）
    $user = UserModel::find($uid);
    if (!$user) {
      throw new ApiException('用户不存在');
    }

    // 游戏标识全局唯一
    $exists = UserIdentModel::where('ident_type', $identType)
      ->where('ident_value', $ident)
      ->first();
    if ($exists) {
      if ((int) $exists->user_id === $uid) {
        return success([], '该标识已绑定此用户, 无需重复绑定');
      }
      throw new ApiException('该游戏标识已绑定其他账号');
    }

    // 成员关系: 无则自动加入 member, banned 拒绝
    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if ($member && $member->status === 'banned') {
      throw new ApiException('该用户已被空间禁用, 无法绑定');
    }
    if (!$member) {
      $member = new ServerMemberModel();
      $member->server_id = $sid;
      $member->user_id = $uid;
      $member->role_key = 'member';
      $member->source = 'bind';
      $member->joined_time = date('Y-m-d H:i:s');
    }
    $member->status = 'active';
    $member->save();

    $row = new UserIdentModel();
    $row->user_id = $uid;
    $row->ident_type = $identType;
    $row->ident_value = $ident;
    $row->bound_server_id = $sid;
    $row->created_time = date('Y-m-d H:i:s');
    $row->save();

    AuditService::log((int) $request->uid, 'server', 'ident.bind', [
      'server_id' => $sid,
      'target_type' => 'ident',
      'target_id' => $row->id,
      'detail' => ['ident' => '...' . substr($ident, -8), 'user_id' => $uid],
    ]);

    // 只回显尾号, 不回传完整标识
    return success(
      [
        'ident_tail' => '...' . substr($ident, -8),
        'user' => ['id' => (int) $user->id, 'username' => $user->username],
      ],
      '绑定成功, 玩家已加入空间',
    );
  }

  /**
   * 解绑（仅限本空间产生的绑定记录）
   */
  #[OA\Post(
    path: '/api/server/{sid}/idents/{id}/unbind',
    operationId: 'serverIdentUnbind',
    summary: '解绑游戏标识',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅允许删除在本空间产生的标识记录；不存在或其他空间返回 400。',
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
        name: 'id',
        in: 'path',
        required: true,
        description: '绑定记录 ID',
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
  public function unbind(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $row = UserIdentModel::where('id', $id)
      ->where('bound_server_id', $sid)
      ->first();
    if (!$row) {
      throw new ApiException('绑定记录不存在或不属于本空间');
    }
    $row->delete();

    AuditService::log((int) $request->uid, 'server', 'ident.unbind', [
      'server_id' => $sid,
      'target_type' => 'ident',
      'target_id' => $id,
      'detail' => ['user_id' => $row->user_id],
    ]);
    return success([], '已解绑');
  }
}
