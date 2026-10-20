<?php

namespace app\server;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\PluginBindingModel;
use app\common\model\PluginModel;
use app\common\model\ServerAccountModel;
use app\common\services\AuditService;
use app\common\services\PermService;

use support\Request;
use support\Response;

/**
 * 空间管理端-插件绑定码（自由生成, 一空间一插件一码, 可重置/停用）
 */
class BindingController
{
  /**
   * 绑定码列表 + 可选在线插件
   */
  #[OA\Get(
    path: '/api/server/{sid}/bindings',
    operationId: 'serverBindingList',
    summary: '绑定码与在线插件选项',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。绑定码按 ID 倒序；完整序列化包含 code、server_key_hash、features 和心跳字段。plugin 仅选取 id/slug/name/status；plugin_options 仅含 online 插件并按名称排序。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/ServerBindingsData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function index(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $list = PluginBindingModel::where('server_id', $sid)
      ->with('plugin:id,slug,name,status')
      ->orderByDesc('id')
      ->get();

    $options = PluginModel::where('status', 'online')
      ->orderBy('name')
      ->get(['id', 'slug', 'name']);

    return success([
      'list' => $list,
      'plugin_options' => $options,
      'server_status' => ServerAccountModel::find($sid)?->status,
    ]);
  }

  /**
   * 生成绑定码（插件须在线; 空间须 active; 一插件一码）
   */
  #[OA\Post(
    path: '/api/server/{sid}/bindings',
    operationId: 'serverBindingCreate',
    summary: '生成插件绑定码',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。空间须 active，插件须 online，一空间一插件仅一个绑定码；重复时须重置。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\JsonContent(ref: '#/components/schemas/ServerPluginIdInput'),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ServerPluginIdInput',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/ServerBindingCodeData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function create(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    if (ServerAccountModel::find($sid)?->status !== 'active') {
      throw new ApiException('空间已被暂停或注销, 无法生成绑定码');
    }

    $pluginId = (int) $request->post('plugin_id', 0);
    $plugin = PluginModel::where('id', $pluginId)
      ->where('status', 'online')
      ->first();
    if (!$plugin) {
      throw new ApiException('插件不存在或未上线');
    }

    if (
      PluginBindingModel::where('server_id', $sid)
        ->where('plugin_id', $pluginId)
        ->first()
    ) {
      throw new ApiException('该插件已生成过绑定码, 请使用重置');
    }

    $binding = new PluginBindingModel();
    $binding->server_id = $sid;
    $binding->plugin_id = $pluginId;
    $binding->code = $this->genCode();
    $binding->status = 'active';
    $binding->server_key_hash = '';
    $binding->features = [];
    $binding->created_by = (int) $request->uid;
    $binding->save();

    AuditService::log((int) $request->uid, 'server', 'binding.create', [
      'server_id' => $sid,
      'target_type' => 'binding',
      'target_id' => $binding->id,
      'detail' => ['plugin' => $plugin->slug],
    ]);

    return success(['code' => $binding->code], '绑定码已生成, 请填入插件配置');
  }

  /**
   * 重置绑定码（旧码立即失效 + 解除服务器绑定 hash）
   */
  #[OA\Post(
    path: '/api/server/{sid}/bindings/{id}/reset',
    operationId: 'serverBindingReset',
    summary: '重置插件绑定码',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅当前空间绑定；旧码立即失效，清空 server_key_hash 并设 active，清理权限缓存。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '插件绑定码记录 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/ServerBindingCodeData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function reset(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $binding = PluginBindingModel::where('id', $id)
      ->where('server_id', $sid)
      ->first();
    if (!$binding) {
      throw new ApiException('绑定码不存在');
    }

    $binding->code = $this->genCode();
    $binding->server_key_hash = '';
    $binding->status = 'active';
    $binding->save();
    PermService::clearCache($sid, (string) $binding->plugin->slug);

    AuditService::log((int) $request->uid, 'server', 'binding.reset', [
      'server_id' => $sid,
      'target_type' => 'binding',
      'target_id' => $id,
    ]);
    return success(
      ['code' => $binding->code],
      '已重置, 新绑定码请立即更新到插件配置',
    );
  }

  /**
   * 停用绑定码（空间自行停用, 可再启用）
   */
  #[OA\Post(
    path: '/api/server/{sid}/bindings/{id}/disable',
    operationId: 'serverBindingDisable',
    summary: '停用插件绑定码',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅当前空间绑定，状态改为 disabled 并清理权限缓存；当前实现不额外检查空间或插件状态。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '插件绑定码记录 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function disable(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $binding = PluginBindingModel::where('id', $id)
      ->where('server_id', $sid)
      ->first();
    if (!$binding) {
      throw new ApiException('绑定码不存在');
    }
    $binding->status = 'disabled';
    $binding->save();
    PermService::clearCache($sid, (string) $binding->plugin->slug);

    AuditService::log((int) $request->uid, 'server', 'binding.disable', [
      'server_id' => $sid,
      'target_type' => 'binding',
      'target_id' => $id,
    ]);
    return success([], '已停用');
  }

  /**
   * 重新启用
   */
  #[OA\Post(
    path: '/api/server/{sid}/bindings/{id}/enable',
    operationId: 'serverBindingEnable',
    summary: '启用插件绑定码',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅当前空间绑定，状态改为 active 并清理权限缓存；当前实现不额外检查空间或插件状态。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '插件绑定码记录 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function enable(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $binding = PluginBindingModel::where('id', $id)
      ->where('server_id', $sid)
      ->first();
    if (!$binding) {
      throw new ApiException('绑定码不存在');
    }
    if (ServerAccountModel::find($sid)?->status !== 'active') {
      throw new ApiException('空间已被暂停, 无法启用绑定码');
    }
    $binding->status = 'active';
    $binding->save();
    PermService::clearCache($sid, (string) $binding->plugin->slug);

    AuditService::log((int) $request->uid, 'server', 'binding.enable', [
      'server_id' => $sid,
      'target_type' => 'binding',
      'target_id' => $id,
    ]);
    return success([], '已启用');
  }

  /**
   * 生成 EX-XXXXX-XXXXX-XXXXX-XXXXX（去除易混淆字符 0/O/1/I）
   */
  private function genCode(): string
  {
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    do {
      $groups = [];
      for ($g = 0; $g < 4; $g++) {
        $s = '';
        for ($i = 0; $i < 5; $i++) {
          $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $groups[] = $s;
      }
      $code = 'EX-' . implode('-', $groups);
    } while (PluginBindingModel::where('code', $code)->exists());

    return $code;
  }
}
