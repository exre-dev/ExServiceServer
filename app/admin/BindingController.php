<?php

namespace app\admin;

use OpenApi\Attributes as OA;

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
  #[OA\Get(
    path: '/api/admin/bindings',
    operationId: 'adminBindingIndex',
    summary: '全局绑定码列表',
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
          maximum: 50,
          default: 10,
        ),
      ),
      new OA\Parameter(
        name: 'status',
        in: 'query',
        required: false,
        description: '非空时按状态筛选',
        schema: new OA\Schema(
          type: 'string',
          enum: ['active', 'revoked', 'disabled'],
        ),
      ),
      new OA\Parameter(
        name: 'server_id',
        in: 'query',
        required: false,
        description: '大于0时筛选空间',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'plugin_id',
        in: 'query',
        required: false,
        description: '大于0时筛选插件',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'keyword',
        in: 'query',
        required: false,
        description: '关键词，去除首尾空白后模糊搜索',
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
                    ref: '#/components/schemas/AdminBindingListItem',
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
  #[OA\Post(
    path: '/api/admin/bindings/{id}/revoke',
    operationId: 'adminBindingRevoke',
    summary: '吊销绑定码',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。已吊销或不存在返回400；吊销后清缓存，插件下次心跳立即感知。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '记录 ID',
        schema: new OA\Schema(type: 'integer'),
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
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
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
