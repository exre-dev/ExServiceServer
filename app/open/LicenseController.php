<?php

namespace app\open;

use app\common\model\ServerAccountModel;
use app\common\services\PermService;
use support\Redis;
use support\Request;
use support\Response;

/**
 * 开放 API-许可校验与心跳
 *
 * POST /api/open/license/validate {bind_code, server_key_hash}
 * 返回 status: active | revoked | expired | disabled | unknown_code(由中间件403) |
 *              server_mismatch | plugin_offline | server_suspended
 * 插件端约定: revoked 立即停用不重试; 其余非 active 走离线宽限。
 */
class LicenseController
{
  protected $noNeedLogin = '*';

  public function validate(Request $request): Response
  {
    $binding = $request->binding;
    $hash = trim((string) $request->post('server_key_hash', ''));
    if ($hash === '') {
      return fail('缺少 server_key_hash', 400);
    }

    $status = 'active';

    // 状态链: 吊销 > 自停 > 过期 > 插件下架 > 空间暂停
    if ($binding->status === 'revoked') {
      $status = 'revoked';
    } elseif ($binding->status === 'disabled') {
      $status = 'disabled';
    } elseif (
      $binding->expires_time &&
      strtotime((string) $binding->expires_time) < time()
    ) {
      $status = 'expired';
    } elseif (!$binding->plugin || $binding->plugin->status !== 'online') {
      $status = 'plugin_offline';
    } elseif (
      ServerAccountModel::find($binding->server_id)?->status !== 'active'
    ) {
      $status = 'server_suspended';
    }

    // 服务器绑定: 首次校验锁定哈希, 已锁定则强匹配
    if ($status === 'active') {
      if ($binding->server_key_hash === '') {
        $binding->server_key_hash = $hash;
      } elseif ($binding->server_key_hash !== $hash) {
        $status = 'server_mismatch';
      }
    }

    $features = [];
    if ($status === 'active') {
      $binding->last_heartbeat = date('Y-m-d H:i:s');
      $binding->last_heartbeat_ip = $request->getRealIp() ?: '';
      $binding->save();

      $features = PermService::serverFeatures($binding);

      // 心跳缓存（吊销/重置/改权限时由管理端主动删除）
      $slug = $request->open_plugin_slug;
      Redis::setex(
        "lic:{$binding->server_id}:{$slug}",
        300,
        json_encode([
          'status' => 'active',
          'features' => $features,
          'expires_time' => $binding->expires_time,
        ]),
      );
    }

    $syncToken = md5(
      json_encode($features) .
        '|' .
        $binding->code .
        '|' .
        $binding->updated_time,
    );

    return success([
      'status' => $status,
      'plugin' => $request->open_plugin_slug,
      'features' => $features,
      'expires_time' => $binding->expires_time,
      'sync_token' => $syncToken,
    ]);
  }
}
