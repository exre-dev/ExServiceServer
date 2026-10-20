<?php

namespace app\open;

use app\common\middleware\BindCodeAuth;
use app\common\model\ServerMemberModel;
use app\common\model\UserIdentModel;
use app\common\services\PermService;
use support\Redis;
use support\Request;
use support\Response;

/**
 * 开放 API-成员权限批量下发（插件心跳顺带拉取, 进服零请求）
 *
 * GET /api/open/perm/batch （X-Bind-Code 鉴权, 插件由绑定码本身确定）
 * 返回该空间全部 active 成员的 fivem_license 标识及最终权限, 60s 缓存。
 */
class PermController
{
  protected $noNeedLogin = '*';

  /**
   * 权限版本号（EXCore ≥1.1 每 30s 轮询, 版本变化即拉全量——「下发权限」手动同步通道）
   */
  public function rev(Request $request): Response
  {
    BindCodeAuth::assertActive($request);

    $serverId = (int) $request->open_server_id;
    $slug = $request->open_plugin_slug;

    return success([
      'rev' => (int) (Redis::get("perm:rev:{$serverId}:{$slug}") ?: 0),
    ]);
  }

  public function batch(Request $request): Response
  {
    BindCodeAuth::assertActive($request);

    $binding = $request->binding;
    $serverId = (int) $request->open_server_id;
    $slug = $request->open_plugin_slug;

    $cacheKey = "perm:{$serverId}:{$slug}";
    $cached = Redis::get($cacheKey);
    if ($cached !== null) {
      return success(json_decode($cached, true) ?: []);
    }

    // 本空间 active 成员
    $memberIds = ServerMemberModel::where('server_id', $serverId)
      ->where('status', 'active')
      ->pluck('user_id')
      ->all();
    if (!$memberIds) {
      $payload = ['list' => []];
      Redis::setex($cacheKey, 60, json_encode($payload));
      return success($payload);
    }

    // 成员的 fivem_license 标识 → 统一权限判定
    $idents = UserIdentModel::where('ident_type', 'fivem_license')
      ->whereIn('user_id', $memberIds)
      ->get();

    $list = [];
    foreach ($idents as $row) {
      $perm = PermService::canUse((int) $row->user_id, $serverId, $slug);
      if ($perm === null) {
        continue;
      }
      $list[] = [
        'ident' => (string) $row->ident_value,
        'allowed' => (bool) $perm['allowed'],
        'features' => $perm['features'],
      ];
    }

    $payload = ['list' => $list];
    Redis::setex($cacheKey, 60, json_encode($payload));
    return success($payload);
  }
}
