<?php

namespace app\server;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\ServerAccountModel;
use app\common\model\ServerMemberModel;
use app\common\services\AuditService;

use support\Request;
use support\Response;

/**
 * 空间管理端-空间本体（我的空间/概览/设置）
 */
class ServerController
{
  /**
   * 我管理的空间（owner/admin, 供前端空间切换器）
   * 注意: 此接口在 {sid} 路由组之外, 不走 ServerMember 中间件
   */
  #[OA\Get(
    path: '/api/server/mine',
    operationId: 'serverMine',
    summary: '我管理的空间',
    description: '需登录；仅返回当前用户 active owner/admin 成员关系对应的 active 空间。不经过 ServerMember。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
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
              ref: '#/components/schemas/ServerMineData',
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
  public function mine(Request $request): Response
  {
    $rows = ServerMemberModel::where('user_id', $request->uid)
      ->whereIn('role_key', ['owner', 'admin'])
      ->where('status', 'active')
      ->with('server:id,name,slug,status,logo')
      ->get();

    $list = $rows
      ->filter(fn($r) => $r->server && $r->server->status === 'active')
      ->map(
        fn($r) => [
          'id' => (int) $r->server->id,
          'name' => $r->server->name,
          'slug' => $r->server->slug,
          'logo' => $r->server->logo,
          'my_role' => $r->role_key,
        ],
      )
      ->values();

    return success(['list' => $list]);
  }

  /**
   * 空间概览（任意 active 成员可看）
   */
  #[OA\Get(
    path: '/api/server/{sid}',
    operationId: 'serverOverview',
    summary: '空间概览',
    description: '任意 active 成员可查看；统计 active 成员、全部绑定码及本空间产生的游戏标识。当前返回完整空间模型（含 note）。',
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
              ref: '#/components/schemas/ServerOverviewData',
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
  public function overview(Request $request, int $sid): Response
  {
    $server = ServerAccountModel::find($sid);
    if (!$server) {
      throw new ApiException('空间不存在');
    }

    return success([
      'server' => $server,
      'my_role' => (string) $request->server_role,
      'member_count' => ServerMemberModel::where('server_id', $sid)
        ->where('status', 'active')
        ->count(),
      'binding_count' => \app\common\model\PluginBindingModel::where(
        'server_id',
        $sid,
      )->count(),
      'ident_count' => \app\common\model\UserIdentModel::where(
        'bound_server_id',
        $sid,
      )->count(),
    ]);
  }

  /**
   * 更新空间设置（仅 Owner）
   */
  #[OA\Put(
    path: '/api/server/{sid}',
    operationId: 'serverSettings',
    summary: '更新空间设置',
    description: '需要当前空间 active 成员身份及 owner 角色；权限或空间不存在按当前实现返回 400。仅处理提供的 name/description/contact_qq/logo，未提供字段保持原值。',
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
      required: false,
      content: [
        new OA\JsonContent(ref: '#/components/schemas/ServerSettingsInput'),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ServerSettingsInput',
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
  public function settings(Request $request, int $sid): Response
  {
    ServerMember::assertOwner($request);

    $server = ServerAccountModel::find($sid);
    if (!$server) {
      throw new ApiException('空间不存在');
    }

    $post = $request->post();
    foreach (['name', 'description', 'contact_qq', 'logo'] as $field) {
      if (array_key_exists($field, $post)) {
        $server->$field = (string) $post[$field];
      }
    }
    $server->save();

    AuditService::log((int) $request->uid, 'server', 'server.settings', [
      'server_id' => $sid,
      'target_type' => 'server',
      'target_id' => $sid,
    ]);
    return success();
  }
}
