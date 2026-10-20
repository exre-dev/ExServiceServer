<?php

namespace app\admin;

use OpenApi\Attributes as OA;

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

  #[OA\Get(
    path: '/api/admin/dashboard',
    operationId: 'adminDashboardIndex',
    summary: '平台概览',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
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
              ref: '#/components/schemas/AdminDashboard',
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
