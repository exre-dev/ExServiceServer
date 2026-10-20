<?php

namespace app\server;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\AuthSessionModel;
use app\common\model\OAuthClientModel;
use app\common\model\ServerClientLinkModel;
use app\common\model\ServerMemberModel;
use app\common\services\AuditService;

use support\Redis;
use support\Request;
use support\Response;

/**
 * 空间管理端-应用授权（查看挂在本空间的插件类应用, 仅有断开/恢复权, 无注册权）
 */
class ClientController
{
  /**
   * 挂在本空间的应用列表（type=plugin 且归属本空间）
   */
  #[OA\Get(
    path: '/api/server/{sid}/clients',
    operationId: 'serverClientList',
    summary: '本空间插件应用',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅 type=plugin 且归属当前空间的应用，按 ID 倒序；没有 link 时 link_status 默认 active。只返回选定字段，不返回 client_secret_hash。',
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
              ref: '#/components/schemas/ServerClientsData',
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

    $clients = OAuthClientModel::where('type', 'plugin')
      ->where('server_id', $sid)
      ->orderByDesc('id')
      ->get();

    $links = ServerClientLinkModel::where('server_id', $sid)
      ->get()
      ->keyBy('client_id');

    $list = $clients->map(function ($c) use ($links) {
      $link = $links->get($c->client_id);
      return [
        'id' => (int) $c->id,
        'client_id' => $c->client_id,
        'name' => $c->name,
        'scopes' => $c->scopes,
        'client_status' => $c->status,
        'link_status' => $link ? $link->status : 'active',
        'disconnected_time' => $link?->disconnected_time,
      ];
    });

    return success(['list' => $list]);
  }

  /**
   * 断开应用（该应用名下本空间成员的会话立即失效, 不再能获取本空间数据）
   */
  #[OA\Post(
    path: '/api/server/{sid}/clients/disconnect',
    operationId: 'serverClientDisconnect',
    summary: '断开应用授权',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。应用必须归属本空间；创建/更新 disconnected 关系，并立即撤销该应用名下所有本空间成员的 active 会话（不限成员状态）。',
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
        new OA\JsonContent(ref: '#/components/schemas/ServerClientInput'),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(ref: '#/components/schemas/ServerClientInput'),
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
  public function disconnect(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $clientId = (string) $request->post('client_id', '');
    $client = OAuthClientModel::where('client_id', $clientId)
      ->where('server_id', $sid)
      ->first();
    if (!$client) {
      throw new ApiException('应用不存在或不属于本空间');
    }

    $link = ServerClientLinkModel::where('server_id', $sid)
      ->where('client_id', $clientId)
      ->first();
    if (!$link) {
      $link = new ServerClientLinkModel();
      $link->server_id = $sid;
      $link->client_id = $clientId;
      $link->linked_time = date('Y-m-d H:i:s');
    }
    $link->status = 'disconnected';
    $link->disconnected_time = date('Y-m-d H:i:s');
    $link->save();

    // 下线该应用名下本空间成员的会话
    $memberIds = ServerMemberModel::where('server_id', $sid)
      ->pluck('user_id')
      ->all();
    $revoked = 0;
    if ($memberIds) {
      $sessions = AuthSessionModel::where('client_id', $clientId)
        ->whereIn('user_id', $memberIds)
        ->where('status', 'active')
        ->get();
      foreach ($sessions as $s) {
        Redis::del('sess:' . $s->id);
        $s->status = 'revoked';
        $s->updated_time = date('Y-m-d H:i:s');
        $s->save();
        $revoked++;
      }
    }

    AuditService::log((int) $request->uid, 'server', 'client.disconnect', [
      'server_id' => $sid,
      'client_id' => $clientId,
      'target_type' => 'client',
      'target_id' => $client->id,
      'detail' => ['revoked_sessions' => $revoked],
    ]);
    return success([], "已断开（下线 {$revoked} 个相关会话）");
  }

  /**
   * 恢复应用
   */
  #[OA\Post(
    path: '/api/server/{sid}/clients/connect',
    operationId: 'serverClientConnect',
    summary: '恢复应用授权',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。应用必须归属本空间；已有 link 时仅恢复 active，不清空 disconnected_time；不恢复已撤销会话。',
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
        new OA\JsonContent(ref: '#/components/schemas/ServerClientInput'),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(ref: '#/components/schemas/ServerClientInput'),
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
  public function connect(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $clientId = (string) $request->post('client_id', '');
    $client = OAuthClientModel::where('client_id', $clientId)
      ->where('server_id', $sid)
      ->first();
    if (!$client) {
      throw new ApiException('应用不存在或不属于本空间');
    }

    $link = ServerClientLinkModel::where('server_id', $sid)
      ->where('client_id', $clientId)
      ->first();
    if ($link) {
      $link->status = 'active';
      $link->save();
    }

    AuditService::log((int) $request->uid, 'server', 'client.connect', [
      'server_id' => $sid,
      'client_id' => $clientId,
      'target_type' => 'client',
      'target_id' => $client->id,
    ]);
    return success([], '已恢复');
  }
}
