<?php

namespace app\admin;

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
