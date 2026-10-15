<?php

namespace app\admin;

use app\common\model\AuditLogModel;

use support\Request;
use support\Response;

/**
 * 平台管理端-审计日志查询
 */
class LogController
{
  public function index(Request $request): Response
  {
    $page = max(1, (int) $request->get('page', 1));
    $pageSize = min(100, max(1, (int) $request->get('page_size', 20)));
    $action = trim((string) $request->get('action', ''));
    $actorUserId = (int) $request->get('actor_user_id', 0);
    $serverId = (int) $request->get('server_id', 0);
    $clientId = trim((string) $request->get('client_id', ''));

    $q = AuditLogModel::query();
    if ($action !== '') {
      $q->where('action', 'like', "{$action}%");
    }
    if ($actorUserId > 0) {
      $q->where('actor_user_id', $actorUserId);
    }
    if ($serverId > 0) {
      $q->where('server_id', $serverId);
    }
    if ($clientId !== '') {
      $q->where('client_id', $clientId);
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    return success([
      'list' => $pager->items(),
      'total' => $pager->total(),
    ]);
  }
}
