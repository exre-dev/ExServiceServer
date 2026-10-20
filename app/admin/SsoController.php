<?php

namespace app\admin;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\model\OAuthClientModel;
use app\common\model\UserModel;
use app\common\services\AuditService;
use app\common\services\OAuthService;
use support\Redis;
use support\Request;
use support\Response;

/**
 * 设备/SSO 授权请求管理（平台管理端, /api/admin/sso-requests）
 *
 * 设备码与 SSO 请求均存 Redis（10 分钟短命），列表实时汇总；
 * 撤销后浏览器与插件轮询立即失效。
 */
class SsoController
{
  /**
   * 当前活跃的设备/SSO 请求列表
   */
  #[OA\Get(
    path: '/api/admin/sso-requests',
    operationId: 'adminSsoIndex',
    summary: '活跃设备及SSO请求列表',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。实时读取 Redis 短期请求，按 created_ago 降序，不分页。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
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
                  items: new OA\Items(
                    ref: '#/components/schemas/AdminSsoRequest',
                  ),
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
    $items = [];
    // 设备请求键短命（10 分钟 TTL）且量小, KEYS 直查即可（SCAN 引用游标经门面转发行为不稳）
    $keys = Redis::keys(OAuthService::DEVICE_PREFIX . '*');
    $prefix = (string) Redis::connection()
      ->client()
      ->getOption(\Redis::OPT_PREFIX);
    foreach ((array) $keys as $key) {
      $key = (string) $key;
      if ($prefix !== '' && str_starts_with($key, $prefix)) {
        $key = substr($key, strlen($prefix));
      }
      // 跳过反向索引键（device:uc:* / device:sso:*）
      if (
        str_starts_with($key, OAuthService::DEVICE_UC_PREFIX) ||
        str_starts_with($key, OAuthService::DEVICE_SSO_PREFIX)
      ) {
        continue;
      }
      $raw = Redis::get($key);
      if ($raw === null) {
        continue;
      }
      $data = json_decode((string) $raw, true);
      if (!is_array($data)) {
        continue;
      }
      $items[] = [
        'device_code' => substr($key, strlen(OAuthService::DEVICE_PREFIX)),
        'client_id' => (string) ($data['client_id'] ?? ''),
        'user_code' => (string) ($data['user_code'] ?? ''),
        'sso_request_id' => (string) ($data['sso_request_id'] ?? ''),
        'status' => (string) ($data['status'] ?? ''),
        'user_id' => (int) ($data['user_id'] ?? 0) ?: null,
        'ttl' => max(0, (int) Redis::ttl($key)),
        'created_ago' => max(
          0,
          OAuthService::DEVICE_TTL - max(0, (int) Redis::ttl($key)),
        ),
      ];
    }

    // 关联应用名与用户名
    $clientIds = array_values(array_unique(array_column($items, 'client_id')));
    $clients = OAuthClientModel::whereIn('client_id', $clientIds)
      ->get()
      ->keyBy('client_id');
    $userIds = array_values(array_filter(array_column($items, 'user_id')));
    $users = UserModel::whereIn('id', $userIds)->get()->keyBy('id');
    foreach ($items as &$item) {
      $item['client_name'] =
        $clients->get($item['client_id'])?->name ?? '(已删除)';
      $item['username'] = isset($item['user_id'])
        ? $users->get($item['user_id'])?->username ?? ''
        : '';
    }
    unset($item);

    usort($items, fn($a, $b) => $b['created_ago'] <=> $a['created_ago']);
    return success(['list' => array_values($items), 'total' => count($items)]);
  }

  /**
   * 撤销请求（删键, 轮询方立即失效）
   */
  #[OA\Post(
    path: '/api/admin/sso-requests/{deviceCode}/revoke',
    operationId: 'adminSsoRevoke',
    summary: '撤销设备或SSO请求',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。删除请求与反向索引；不存在或已过期返回404。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'deviceCode',
        in: 'path',
        required: true,
        description: '设备请求 device_code',
        schema: new OA\Schema(type: 'string'),
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
        response: 404,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function revoke(Request $request, string $deviceCode): Response
  {
    if (!OAuthService::deviceRevoke($deviceCode)) {
      throw new ApiException('请求不存在或已过期', 404);
    }

    AuditService::log(null, 'platform', 'oauth.device_revoke', [
      'target_type' => 'device_request',
      'target_id' => $deviceCode,
    ]);
    return success([], '请求已撤销');
  }
}
