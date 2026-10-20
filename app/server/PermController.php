<?php

namespace app\server;

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
