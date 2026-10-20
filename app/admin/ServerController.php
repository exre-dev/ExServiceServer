<?php

namespace app\admin;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\model\ServerAccountModel;
use app\common\model\ServerMemberModel;
use app\common\model\UserModel;
use app\common\services\AuditService;
use app\common\services\PermService;

use support\Request;
use support\Response;

/**
 * 平台管理端-空间管理（B2 空间, 超管创建与管控）
 */
class ServerController
{
  /**
   * 空间列表（分页/筛选）
   */
  #[OA\Get(
    path: '/api/admin/servers',
    operationId: 'adminServerIndex',
    summary: '空间列表',
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
          enum: ['active', 'suspended', 'closed'],
        ),
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
                    ref: '#/components/schemas/AdminServerListItem',
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
    $keyword = trim((string) $request->get('keyword', ''));

    $q = ServerAccountModel::with('owner:id,email,username')->withCount([
      'members',
      'bindings',
    ]);

    if ($status !== '') {
      $q->where('status', $status);
    }
    if ($keyword !== '') {
      $q->where(function ($sq) use ($keyword) {
        $sq
          ->where('name', 'like', "%{$keyword}%")
          ->orWhere('slug', 'like', "%{$keyword}%");
      });
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    return success([
      'list' => $pager->items(),
      'total' => $pager->total(),
    ]);
  }

  /**
   * 全部 active 空间（下拉选择用, 不分页）
   */
  #[OA\Get(
    path: '/api/admin/server-options',
    operationId: 'adminServerOptions',
    summary: '可用空间选项',
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
              type: 'object',
              properties: [
                new OA\Property(
                  property: 'list',
                  type: 'array',
                  items: new OA\Items(
                    ref: '#/components/schemas/AdminServerBrief',
                  ),
                ),
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
  public function options(Request $request): Response
  {
    $list = ServerAccountModel::where('status', 'active')
      ->orderBy('name')
      ->get(['id', 'name', 'slug']);
    return success(['list' => $list]);
  }

  /**
   * 创建空间（指定 Owner, Owner 自动成为 owner 成员）
   */
  #[OA\Post(
    path: '/api/admin/servers',
    operationId: 'adminServerCreate',
    summary: '创建空间',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。创建 active 空间，同时将指定 Owner 加入 owner 成员关系。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(property: 'name', type: 'string', minLength: 1),
          new OA\Property(
            property: 'slug',
            type: 'string',
            pattern: '^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$',
            minLength: 3,
            maxLength: 32,
          ),
          new OA\Property(
            property: 'owner_email',
            type: 'string',
            description: '对应存在且未封禁的用户',
          ),
          new OA\Property(property: 'description', type: 'string'),
          new OA\Property(property: 'contact_qq', type: 'string'),
          new OA\Property(property: 'note', type: 'string'),
        ],
        required: ['name', 'slug', 'owner_email'],
      ),
    ),
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
              ref: '#/components/schemas/AdminId',
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
  public function create(Request $request): Response
  {
    $post = $request->post();
    if (hasMissingKeys($post, ['name', 'slug', 'owner_email'])) {
      throw new ApiException('参数不匹配');
    }

    $name = trim((string) $post['name']);
    $slug = trim((string) $post['slug']);
    $ownerEmail = trim((string) $post['owner_email']);

    if (
      $name === '' ||
      !preg_match('/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/', $slug)
    ) {
      throw new ApiException('名称必填, slug 为 3-32 位小写字母数字连字符');
    }

    $owner = UserModel::where('email', $ownerEmail)->first();
    if (!$owner) {
      throw new ApiException("Owner 用户不存在: {$ownerEmail}");
    }
    if ((int) $owner->ban !== 0) {
      throw new ApiException('Owner 用户已被封禁');
    }
    if (ServerAccountModel::where('slug', $slug)->first()) {
      throw new ApiException('slug 已被占用');
    }

    $server = new ServerAccountModel();
    $server->name = $name;
    $server->slug = $slug;
    $server->description = (string) ($post['description'] ?? '');
    $server->owner_user_id = (int) $owner->id;
    $server->contact_qq = (string) ($post['contact_qq'] ?? '');
    $server->status = 'active';
    $server->note = (string) ($post['note'] ?? '');
    $server->save();

    // Owner 成员关系（来源: 超管指定）
    $member = new ServerMemberModel();
    $member->server_id = $server->id;
    $member->user_id = $owner->id;
    $member->role_key = 'owner';
    $member->status = 'active';
    $member->source = 'grant';
    $member->joined_time = date('Y-m-d H:i:s');
    $member->save();

    AuditService::log((int) $request->uid, 'platform', 'server.create', [
      'server_id' => $server->id,
      'target_type' => 'server',
      'target_id' => $server->id,
      'detail' => ['slug' => $slug, 'owner' => $ownerEmail],
    ]);

    return success(['id' => $server->id], '空间创建成功');
  }

  /**
   * 空间详情（基本信息 + 成员 + 绑定码）
   */
  #[OA\Get(
    path: '/api/admin/servers/{sid}',
    operationId: 'adminServerDetail',
    summary: '空间详情',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'sid',
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
              ref: '#/components/schemas/AdminServerDetail',
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
  public function detail(Request $request, int $sid): Response
  {
    $server = ServerAccountModel::with('owner:id,email,username')->find($sid);
    if (!$server) {
      throw new ApiException('空间不存在');
    }

    $members = ServerMemberModel::where('server_id', $sid)
      ->with('user:id,email,username,avatar')
      ->orderByDesc('id')
      ->get();

    $bindings = \app\common\model\PluginBindingModel::where('server_id', $sid)
      ->with('plugin:id,slug,name,status')
      ->get();

    return success([
      'server' => $server,
      'members' => $members,
      'bindings' => $bindings,
    ]);
  }

  /**
   * 更新空间基本信息
   */
  #[OA\Put(
    path: '/api/admin/servers/{sid}',
    operationId: 'adminServerUpdate',
    summary: '更新空间资料',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '记录 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: false,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(property: 'name', type: 'string'),
          new OA\Property(property: 'description', type: 'string'),
          new OA\Property(property: 'contact_qq', type: 'string'),
          new OA\Property(property: 'note', type: 'string'),
          new OA\Property(property: 'logo', type: 'string'),
        ],
      ),
    ),
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
  public function update(Request $request, int $sid): Response
  {
    $server = ServerAccountModel::find($sid);
    if (!$server) {
      throw new ApiException('空间不存在');
    }

    $post = $request->post();
    foreach (['name', 'description', 'contact_qq', 'note', 'logo'] as $field) {
      if (array_key_exists($field, $post)) {
        $server->$field = (string) $post[$field];
      }
    }
    $server->save();

    AuditService::log((int) $request->uid, 'platform', 'server.update', [
      'server_id' => $sid,
      'target_type' => 'server',
      'target_id' => $sid,
    ]);
    return success();
  }

  /**
   * 空间状态变更（暂停/恢复/注销）
   * 暂停后其全部绑定码授权自动失效（授权链校验空间状态）, 恢复即生效
   */
  #[OA\Post(
    path: '/api/admin/servers/{sid}/status',
    operationId: 'adminServerStatus',
    summary: '更新空间状态',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。清空空间许可与权限缓存；暂停后其全部绑定码授权失效，恢复后生效。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '记录 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['active', 'suspended', 'closed'],
          ),
        ],
        required: ['status'],
      ),
    ),
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
  public function status(Request $request, int $sid): Response
  {
    $server = ServerAccountModel::find($sid);
    if (!$server) {
      throw new ApiException('空间不存在');
    }

    $status = (string) $request->post('status', '');
    if (!in_array($status, ['active', 'suspended', 'closed'], true)) {
      throw new ApiException('非法状态值');
    }

    $server->status = $status;
    $server->save();

    // 清空该空间许可/权限缓存, 让运行中的插件下次心跳立即感知
    PermService::clearCacheForServer($sid);

    AuditService::log((int) $request->uid, 'platform', 'server.status', [
      'server_id' => $sid,
      'target_type' => 'server',
      'target_id' => $sid,
      'detail' => ['status' => $status],
    ]);
    return success([], "状态已更新为 {$status}");
  }
}
