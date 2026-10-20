<?php

namespace app\server;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\MemberPluginPermModel;
use app\common\model\PluginBindingModel;
use app\common\model\PluginFeatureModel;
use app\common\model\ServerMemberModel;
use app\common\services\AuditService;
use app\common\services\PermService;

use support\Redis;
use support\Request;
use support\Response;

/**
 * 空间管理端-成员插件权限（需求 6: 成员 × 功能点矩阵）
 */
class PermController
{
  /**
   * 按成员聚合: 指定成员在全部已绑定插件上的权限状态（左右主从布局用）
   * GET /server/{sid}/member-perms?user_id=
   */
  #[OA\Get(
    path: '/api/server/{sid}/member-perms',
    operationId: 'serverMemberPermissions',
    summary: '成员全部插件权限',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。目标须为本空间 active 成员；列出全部绑定插件，按绑定 ID 倒序。无覆盖时 allowed=true、features=null；无插件时 list=[] 且 member 不含 avatar。',
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
        name: 'user_id',
        in: 'query',
        required: true,
        description: 'active 成员用户 ID',
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
              ref: '#/components/schemas/ServerMemberPermissionsData',
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
  public function member_perms(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $uid = (int) $request->get('user_id', 0);
    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->where('status', 'active')
      ->with('user:id,email,username,avatar')
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在或非 active');
    }

    $bindings = PluginBindingModel::where('server_id', $sid)
      ->with('plugin:id,slug,name,status')
      ->orderByDesc('id')
      ->get();
    if ($bindings->isEmpty()) {
      return success([
        'member' => [
          'user_id' => (int) $member->user_id,
          'username' => $member->user?->username,
          'email' => $member->user?->email,
          'role_key' => $member->role_key,
        ],
        'list' => [],
      ]);
    }

    $features = PluginFeatureModel::whereIn(
      'plugin_id',
      $bindings->pluck('plugin_id'),
    )
      ->orderBy('id')
      ->get()
      ->groupBy('plugin_id');

    $perms = MemberPluginPermModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->get()
      ->keyBy('plugin_id');

    $list = $bindings
      ->map(function ($b) use ($features, $perms) {
        $perm = $perms->get($b->plugin_id);
        $featureList = ($features->get($b->plugin_id) ?? collect())
          ->map(
            fn($f) => [
              'feature_key' => $f->feature_key,
              'name' => $f->name,
              'default_enabled' => (bool) $f->default_enabled,
            ],
          )
          ->values();

        return [
          'binding_id' => (int) $b->id,
          'binding_status' => $b->status,
          'plugin_id' => (int) $b->plugin_id,
          'plugin_name' => $b->plugin?->name,
          'plugin_slug' => $b->plugin?->slug,
          'plugin_status' => $b->plugin?->status,
          'allowed' => $perm ? (bool) $perm->allowed : true,
          'features' =>
            $perm && is_array($perm->features) && $perm->features !== []
              ? $perm->features
              : null,
          'has_perm_row' => (bool) $perm,
          'feature_list' => $featureList,
        ];
      })
      ->values();

    return success([
      'member' => [
        'user_id' => (int) $member->user_id,
        'username' => $member->user?->username,
        'email' => $member->user?->email,
        'avatar' => $member->user?->avatar,
        'role_key' => $member->role_key,
      ],
      'list' => $list,
    ]);
  }

  /**
   * 权限矩阵: 指定插件的全部 active 成员 × 功能点
   */
  #[OA\Get(
    path: '/api/server/{sid}/plugin-perms',
    operationId: 'serverPermissionMatrix',
    summary: '插件成员权限矩阵',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。插件必须已在本空间生成绑定码；仅 active 成员，按 role_key 倒序。无覆盖时 allowed=true、features=null；返回绑定码选定字段与完整功能点。',
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
        name: 'plugin_id',
        in: 'query',
        required: true,
        description: '已绑定插件 ID',
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
              ref: '#/components/schemas/ServerPermissionMatrixData',
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
  public function matrix(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $pluginId = (int) $request->get('plugin_id', 0);
    $binding = PluginBindingModel::where('server_id', $sid)
      ->where('plugin_id', $pluginId)
      ->first();
    if (!$binding) {
      throw new ApiException('该插件未生成绑定码');
    }

    $features = PluginFeatureModel::where('plugin_id', $pluginId)
      ->orderBy('id')
      ->get();

    $members = ServerMemberModel::where('server_id', $sid)
      ->where('status', 'active')
      ->with('user:id,email,username')
      ->orderByDesc('role_key')
      ->get();

    $perms = MemberPluginPermModel::where('server_id', $sid)
      ->where('plugin_id', $pluginId)
      ->get()
      ->keyBy('user_id');

    $rows = $members->map(function ($m) use ($perms) {
      $perm = $perms->get($m->user_id);
      return [
        'user_id' => (int) $m->user_id,
        'username' => $m->user?->username,
        'email' => $m->user?->email,
        'role_key' => $m->role_key,
        'allowed' => $perm ? (bool) $perm->allowed : true,
        'features' =>
          $perm && is_array($perm->features) && $perm->features !== []
            ? $perm->features
            : null,
        'has_perm_row' => (bool) $perm,
      ];
    });

    return success([
      'binding' => $binding->only([
        'id',
        'plugin_id',
        'status',
        'features',
        'expires_time',
      ]),
      'features' => $features,
      'rows' => $rows,
    ]);
  }

  /**
   * 保存某成员的权限（allowed + 显式功能覆盖; 保存即清缓存, 插件下次心跳生效）
   */
  #[OA\Post(
    path: '/api/server/{sid}/plugin-perms',
    operationId: 'serverPermissionSave',
    summary: '保存成员插件权限',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。插件须已绑定且目标为 active 成员；features 仅保留已知功能键并转换为 bool。保存后清缓存，插件下次同步生效。',
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
        new OA\JsonContent(
          ref: '#/components/schemas/ServerPermissionSaveInput',
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
  public function save(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $pluginId = (int) $request->post('plugin_id', 0);
    $uid = (int) $request->post('user_id', 0);
    $allowed = (bool) $request->post('allowed', true);
    $features = $request->post('features');

    $binding = PluginBindingModel::where('server_id', $sid)
      ->where('plugin_id', $pluginId)
      ->first();
    if (!$binding) {
      throw new ApiException('该插件未生成绑定码');
    }
    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->where('status', 'active')
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在或非 active');
    }

    // 功能覆盖只保留该插件已知 key
    $knownKeys = PluginFeatureModel::where('plugin_id', $pluginId)
      ->pluck('feature_key')
      ->all();
    $clean = [];
    if (is_array($features)) {
      foreach ($features as $k => $v) {
        if (in_array((string) $k, $knownKeys, true)) {
          $clean[(string) $k] = (bool) $v;
        }
      }
    }

    $perm = MemberPluginPermModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->where('plugin_id', $pluginId)
      ->first();
    if (!$perm) {
      $perm = new MemberPluginPermModel();
      $perm->server_id = $sid;
      $perm->user_id = $uid;
      $perm->plugin_id = $pluginId;
    }
    $perm->allowed = $allowed;
    $perm->features = $features === null ? null : $clean;
    $perm->updated_by = (int) $request->uid;
    $perm->save();

    PermService::clearCache($sid, (string) $binding->plugin->slug);

    AuditService::log((int) $request->uid, 'server', 'perm.save', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
      'detail' => [
        'plugin_id' => $pluginId,
        'allowed' => $allowed,
        'features' => $perm->features,
      ],
    ]);
    return success([], '权限已保存, 插件下次同步生效');
  }

  /**
   * 手动下发权限（清缓存 + 权限版本号 +1, 插件端 EXCore ≥1.1 检测到版本变化数秒内拉取;
   * 不点击则等插件心跳自动同步）
   * POST {plugin_id?} 省略则下发本空间全部插件
   */
  #[OA\Post(
    path: '/api/server/{sid}/plugin-perms/push',
    operationId: 'serverPermissionPush',
    summary: '下发插件权限',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。清缓存并增加权限版本；无可下发绑定返回 400。EXCore >=1.1 可检测版本并拉取，旧版等待心跳。',
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
      required: false,
      content: [
        new OA\JsonContent(
          ref: '#/components/schemas/ServerPermissionPushInput',
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ServerPermissionPushInput',
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
              ref: '#/components/schemas/ServerPermissionPushData',
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
  public function push(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $pluginId = (int) $request->post('plugin_id', 0);
    $bindings = PluginBindingModel::where('server_id', $sid)
      ->with('plugin:id,slug')
      ->get();
    if ($pluginId > 0) {
      $bindings = $bindings->filter(
        fn($b) => (int) $b->plugin_id === $pluginId,
      );
    }
    if ($bindings->isEmpty()) {
      throw new ApiException('没有可下发的插件绑定');
    }

    $pushed = [];
    foreach ($bindings as $b) {
      if (!$b->plugin) {
        continue;
      }
      $slug = (string) $b->plugin->slug;
      PermService::clearCache($sid, $slug);
      Redis::incr("perm:rev:{$sid}:{$slug}");
      $pushed[] = $slug;
    }

    AuditService::log((int) $request->uid, 'server', 'perm.push', [
      'server_id' => $sid,
      'detail' => ['plugins' => $pushed],
    ]);
    return success(
      ['plugins' => $pushed],
      '已下发 ' . count($pushed) . ' 个插件（新版插件数秒内同步, 旧版等心跳）',
    );
  }

  /**
   * 恢复默认（删除该成员此插件的用户级覆盖 → 继承绑定码全量）
   */
  #[OA\Post(
    path: '/api/server/{sid}/plugin-perms/reset',
    operationId: 'serverPermissionReset',
    summary: '恢复成员插件默认权限',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。插件须已绑定；删除目标用户对该插件的覆盖并清缓存，未额外校验目标成员存在。',
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
        new OA\JsonContent(
          ref: '#/components/schemas/ServerPermissionTargetInput',
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ServerPermissionTargetInput',
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
  public function reset(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $pluginId = (int) $request->post('plugin_id', 0);
    $uid = (int) $request->post('user_id', 0);

    $binding = PluginBindingModel::where('server_id', $sid)
      ->where('plugin_id', $pluginId)
      ->first();
    if (!$binding) {
      throw new ApiException('该插件未生成绑定码');
    }

    MemberPluginPermModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->where('plugin_id', $pluginId)
      ->delete();
    PermService::clearCache($sid, (string) $binding->plugin->slug);

    AuditService::log((int) $request->uid, 'server', 'perm.reset', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
      'detail' => ['plugin_id' => $pluginId],
    ]);
    return success([], '已恢复默认（继承全部功能）');
  }
}
