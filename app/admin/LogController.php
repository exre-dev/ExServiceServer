<?php

namespace app\admin;

use OpenApi\Attributes as OA;

use app\common\model\AuditLogModel;

use support\Request;
use support\Response;

/**
 * 平台管理端-审计日志查询
 */
class LogController
{
  #[OA\Get(
    path: '/api/admin/logs',
    operationId: 'adminLogIndex',
    summary: '审计日志列表',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'page',
        in: 'query',
        required: false,
        description: '页码，小于1按1处理',
        schema: new OA\Schema(type: 'integer', minimum: 1, default: 1),
      ),
      new OA\Parameter(
        name: 'page_size',
        in: 'query',
        required: false,
        description: '每页数量，服务端裁剪到允许范围',
        schema: new OA\Schema(
          type: 'integer',
          minimum: 1,
          maximum: 100,
          default: 20,
        ),
      ),
      new OA\Parameter(
        name: 'action',
        in: 'query',
        required: false,
        description: '动作名前缀匹配',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'actor_user_id',
        in: 'query',
        required: false,
        description: '大于0时筛选操作用户',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'server_id',
        in: 'query',
        required: false,
        description: '大于0时筛选空间',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'client_id',
        in: 'query',
        required: false,
        description: '非空时精确匹配 client_id',
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
              type: 'object',
              properties: [
                new OA\Property(
                  property: 'list',
                  type: 'array',
                  items: new OA\Items(
                    ref: '#/components/schemas/AdminAuditLog',
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
