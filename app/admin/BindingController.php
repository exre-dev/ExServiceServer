<?php

namespace app\admin;

use app\common\exception\ApiException;
use app\common\model\PluginBindingModel;
use app\common\services\AuditService;
use app\common\services\PermService;

use support\Request;
use support\Response;

/**
 * 平台管理端-绑定码监管（全局检索/吊销/心跳健康度）
 */
class BindingController
{
  /** 心跳异常阈值: 超过 3 个周期(每周期 600s)视为失联 */
  private const STALE_SECONDS = 1800;

  /**
   * 绑定码列表
   */
  public function index(Request $request): Response
  {
    $page = max(1, (int) $request->get('page', 1));
    $pageSize = min(50, max(1, (int) $request->get('page_size', 10)));
    $status = (string) $request->get('status', '');
    $serverId = (int) $request->get('server_id', 0);
    $pluginId = (int) $request->get('plugin_id', 0);
    $keyword = trim((string) $request->get('keyword', ''));

    $q = PluginBindingModel::with([
      'server:id,name,slug,status',
      'plugin:id,slug,name,status',
    ]);
    if ($status !== '') {
      $q->where('status', $status);
    }
    if ($serverId > 0) {
      $q->where('server_id', $serverId);
    }
    if ($pluginId > 0) {
      $q->where('plugin_id', $pluginId);
    }
    if ($keyword !== '') {
      $q->where('code', 'like', "%{$keyword}%");
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

    // 附加心跳健康度标记
    $list = array_map(function ($row) {
      $item = $row->toArray();
      if ($row->status !== 'active') {
        $item['health'] = 'inactive';
      } elseif (empty($row->last_heartbeat)) {
        $item['health'] = 'never';
      } elseif (
        strtotime((string) $row->last_heartbeat) <
        time() - self::STALE_SECONDS
      ) {
        $item['health'] = 'stale';
      } else {
        $item['health'] = 'ok';
      }
      return $item;
    }, $pager->items());

    return success([
      'list' => $list,
      'total' => $pager->total(),
    ]);
  }

  /**
   * 吊销绑定码（立即失效: 清缓存, 插件下次心跳拿到 revoked）
   */
  public function revoke(Request $request, int $id): Response
  {
    $binding = PluginBindingModel::with('plugin:id,slug')->find($id);
    if (!$binding) {
      throw new ApiException('绑定码不存在');
    }
    if ($binding->status === 'revoked') {
      throw new ApiException('绑定码已被吊销');
    }

    $binding->status = 'revoked';
    $binding->save();

    if ($binding->plugin) {
      PermService::clearCache(
        (int) $binding->server_id,
        (string) $binding->plugin->slug,
      );
    }

    AuditService::log((int) $request->uid, 'platform', 'binding.revoke', [
      'server_id' => $binding->server_id,
      'target_type' => 'binding',
      'target_id' => $id,
      'detail' => ['code' => $binding->code],
    ]);
    return success([], '绑定码已吊销');
  }
}
