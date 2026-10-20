<?php

namespace app\admin;

use app\common\model\AuditLogModel;
use app\common\model\PluginBindingModel;
use app\common\model\PluginModel;
use app\common\model\ServerAccountModel;
use app\common\model\UserModel;

use support\Request;
use support\Response;

/**
 * 平台管理端-概览
 */
class DashboardController
{
  /** 心跳异常阈值秒数 */
  private const STALE_SECONDS = 1800;

  public function index(Request $request): Response
  {
    $now = time();

    $servers = ServerAccountModel::selectRaw('status, count(*) as n')
      ->groupBy('status')
      ->pluck('n', 'status')
      ->all();
    $bindings = PluginBindingModel::selectRaw('status, count(*) as n')
      ->groupBy('status')
      ->pluck('n', 'status')
      ->all();

    // 失联绑定码数（active 但心跳超时/从未心跳——已激活 hash 的才算, 排除从未使用的码）
    $stale = PluginBindingModel::where('status', 'active')
      ->where('server_key_hash', '!=', '')
      ->where(function ($q) use ($now) {
        $q->whereNull('last_heartbeat')->orWhere(
          'last_heartbeat',
          '<',
          date('Y-m-d H:i:s', $now - self::STALE_SECONDS),
        );
      })
      ->count();

    return success([
      'users' => [
        'total' => UserModel::count(),
        'banned' => UserModel::where('ban', 1)->count(),
      ],
      'servers' => [
        'active' => (int) ($servers['active'] ?? 0),
        'suspended' => (int) ($servers['suspended'] ?? 0),
        'closed' => (int) ($servers['closed'] ?? 0),
      ],
      'plugins' => [
        'online' => PluginModel::where('status', 'online')->count(),
        'total' => PluginModel::count(),
      ],
      'bindings' => [
        'active' => (int) ($bindings['active'] ?? 0),
        'revoked' => (int) ($bindings['revoked'] ?? 0),
        'disabled' => (int) ($bindings['disabled'] ?? 0),
        'stale_heartbeat' => $stale,
      ],
      'recent_logs' => AuditLogModel::orderByDesc('id')->limit(10)->get(),
    ]);
  }
}
