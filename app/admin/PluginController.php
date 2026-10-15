<?php

namespace app\admin;

use app\common\exception\ApiException;
use app\common\model\PluginBindingModel;
use app\common\model\PluginFeatureModel;
use app\common\model\PluginModel;
use app\common\services\AuditService;
use app\common\services\PermService;

use support\Request;
use support\Response;

/**
 * 平台管理端-插件目录（上架维护, 无价格——售卖在外部平台）
 */
class PluginController
{
  /**
   * 插件列表
   */
  public function index(Request $request): Response
  {
    $page = max(1, (int) $request->get('page', 1));
    $pageSize = min(50, max(1, (int) $request->get('page_size', 10)));
    $status = (string) $request->get('status', '');
    $keyword = trim((string) $request->get('keyword', ''));

    $q = PluginModel::withCount(['features', 'bindings']);
    if ($status !== '') {
      $q->where('status', $status);
    }
    if ($keyword !== '') {
      $q->where(function ($sq) use ($keyword) {
        $sq->where('name', 'like', "%{$keyword}%")->orWhere('slug', 'like', "%{$keyword}%");
      });
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    return success([
      'list' => $pager->items(),
      'total' => $pager->total(),
    ]);
  }

  /**
   * 全部在线插件（下拉选择用, 不分页）
   */
  public function options(Request $request): Response
  {
    $list = PluginModel::whereIn('status', ['online'])
      ->orderBy('name')
      ->get(['id', 'slug', 'name', 'status']);
    return success(['list' => $list]);
  }

  /**
   * 创建插件
   */
  public function create(Request $request): Response
  {
    $post = $request->post();
    if (hasMissingKeys($post, ["slug", "name"])) {
      throw new ApiException("参数不匹配");
    }

    $slug = trim((string) $post['slug']);
    $name = trim((string) $post['name']);
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/', $slug) || $name === '') {
      throw new ApiException("slug 为 3-32 位小写字母数字连字符, 名称必填");
    }
    if (PluginModel::where('slug', $slug)->first()) {
      throw new ApiException("slug 已存在");
    }

    $plugin = new PluginModel();
    $plugin->slug = $slug;
    $plugin->name = $name;
    $plugin->summary = (string) ($post['summary'] ?? '');
    $plugin->description_md = (string) ($post['description_md'] ?? '');
    $plugin->latest_version = (string) ($post['latest_version'] ?? '');
    $plugin->status = 'draft';
    $plugin->save();

    AuditService::log((int) $request->uid, 'platform', 'plugin.create', [
      'target_type' => 'plugin',
      'target_id' => $plugin->id,
      'detail' => ['slug' => $slug],
    ]);
    return success(['id' => $plugin->id], "插件已创建（草稿）");
  }

  /**
   * 更新插件信息
   */
  public function update(Request $request, int $id): Response
  {
    $plugin = PluginModel::find($id);
    if (!$plugin) {
      throw new ApiException("插件不存在");
    }

    $post = $request->post();
    foreach (['name', 'summary', 'description_md', 'latest_version'] as $field) {
      if (array_key_exists($field, $post)) {
        $plugin->$field = (string) $post[$field];
      }
    }
    $plugin->save();

    AuditService::log((int) $request->uid, 'platform', 'plugin.update', [
      'target_type' => 'plugin',
      'target_id' => $id,
    ]);
    return success();
  }

  /**
   * 上下架（offline 后全部绑定码校验直接拒绝）
   */
  public function status(Request $request, int $id): Response
  {
    $plugin = PluginModel::find($id);
    if (!$plugin) {
      throw new ApiException("插件不存在");
    }

    $status = (string) $request->post('status', '');
    if (!in_array($status, ['draft', 'online', 'offline'], true)) {
      throw new ApiException("非法状态值");
    }

    $plugin->status = $status;
    $plugin->save();

    // 清全部持有该插件的空间缓存
    $bindings = PluginBindingModel::where('plugin_id', $id)->with('server:id,slug')->get();
    foreach ($bindings as $binding) {
      PermService::clearCache((int) $binding->server_id, (string) $plugin->slug);
    }

    AuditService::log((int) $request->uid, 'platform', 'plugin.status', [
      'target_type' => 'plugin',
      'target_id' => $id,
      'detail' => ['status' => $status],
    ]);
    return success([], "插件状态已更新为 {$status}");
  }

  // ==================== 功能点字典 ====================

  /**
   * 功能点列表
   */
  public function features(Request $request, int $id): Response
  {
    $plugin = PluginModel::find($id);
    if (!$plugin) {
      throw new ApiException("插件不存在");
    }
    return success([
      'list' => PluginFeatureModel::where('plugin_id', $id)->orderBy('id')->get(),
      'has_bindings' => PluginBindingModel::where('plugin_id', $id)->exists(),
    ]);
  }

  /**
   * 新增功能点
   */
  public function feature_create(Request $request, int $id): Response
  {
    $plugin = PluginModel::find($id);
    if (!$plugin) {
      throw new ApiException("插件不存在");
    }

    $key = trim((string) $request->post('feature_key', ''));
    $name = trim((string) $request->post('name', ''));
    if (!preg_match('/^[a-z0-9_]{2,32}$/', $key) || $name === '') {
      throw new ApiException("feature_key 为 2-32 位小写字母数字下划线, 名称必填");
    }
    if (PluginFeatureModel::where('plugin_id', $id)->where('feature_key', $key)->first()) {
      throw new ApiException("feature_key 已存在");
    }

    $feature = new PluginFeatureModel();
    $feature->plugin_id = $id;
    $feature->feature_key = $key;
    $feature->name = $name;
    $feature->default_enabled = (bool) $request->post('default_enabled', true);
    $feature->save();

    AuditService::log((int) $request->uid, 'platform', 'feature.create', [
      'target_type' => 'plugin_feature',
      'target_id' => $feature->id,
      'detail' => ['plugin' => $plugin->slug, 'feature_key' => $key],
    ]);
    return success(['id' => $feature->id], "功能点已添加");
  }

  /**
   * 修改功能点（有绑定码使用后禁止改 key, 只能改名/开关）
   */
  public function feature_update(Request $request, int $id, int $fid): Response
  {
    $feature = PluginFeatureModel::where('plugin_id', $id)->find($fid);
    if (!$feature) {
      throw new ApiException("功能点不存在");
    }

    $hasBindings = PluginBindingModel::where('plugin_id', $id)->exists();
    $newKey = trim((string) $request->post('feature_key', $feature->feature_key));
    if ($hasBindings && $newKey !== $feature->feature_key) {
      throw new ApiException("已有绑定码使用该插件, feature_key 不可修改");
    }

    if ($request->post('name') !== null) {
      $feature->name = (string) $request->post('name');
    }
    if ($request->post('feature_key') !== null) {
      $feature->feature_key = $newKey;
    }
    if ($request->post('default_enabled') !== null) {
      $feature->default_enabled = (bool) $request->post('default_enabled');
    }
    $feature->save();

    AuditService::log((int) $request->uid, 'platform', 'feature.update', [
      'target_type' => 'plugin_feature',
      'target_id' => $fid,
    ]);
    return success();
  }

  /**
   * 删除功能点（有绑定码使用时禁止删除）
   */
  public function feature_delete(Request $request, int $id, int $fid): Response
  {
    if (PluginBindingModel::where('plugin_id', $id)->exists()) {
      throw new ApiException("已有绑定码使用该插件, 功能点不可删除（可关闭 default_enabled）");
    }
    PluginFeatureModel::where('plugin_id', $id)->where('id', $fid)->delete();

    AuditService::log((int) $request->uid, 'platform', 'feature.delete', [
      'target_type' => 'plugin_feature',
      'target_id' => $fid,
    ]);
    return success([], "功能点已删除");
  }
}
