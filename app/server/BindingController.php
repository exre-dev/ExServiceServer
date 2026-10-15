<?php

namespace app\server;

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
  public function index(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $list = PluginBindingModel::where('server_id', $sid)
      ->with('plugin:id,slug,name,status')
      ->orderByDesc('id')
      ->get();

    $options = PluginModel::where('status', 'online')->orderBy('name')->get(['id', 'slug', 'name']);

    return success([
      'list' => $list,
      'plugin_options' => $options,
      'server_status' => ServerAccountModel::find($sid)?->status,
    ]);
  }

  /**
   * 生成绑定码（插件须在线; 空间须 active; 一插件一码）
   */
  public function create(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    if (ServerAccountModel::find($sid)?->status !== 'active') {
      throw new ApiException("空间已被暂停或注销, 无法生成绑定码");
    }

    $pluginId = (int) $request->post('plugin_id', 0);
    $plugin = PluginModel::where('id', $pluginId)->where('status', 'online')->first();
    if (!$plugin) {
      throw new ApiException("插件不存在或未上线");
    }

    if (PluginBindingModel::where('server_id', $sid)->where('plugin_id', $pluginId)->first()) {
      throw new ApiException("该插件已生成过绑定码, 请使用重置");
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

    return success(['code' => $binding->code], "绑定码已生成, 请填入插件配置");
  }

  /**
   * 重置绑定码（旧码立即失效 + 解除服务器绑定 hash）
   */
  public function reset(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $binding = PluginBindingModel::where('id', $id)->where('server_id', $sid)->first();
    if (!$binding) {
      throw new ApiException("绑定码不存在");
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
    return success(['code' => $binding->code], "已重置, 新绑定码请立即更新到插件配置");
  }

  /**
   * 停用绑定码（空间自行停用, 可再启用）
   */
  public function disable(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $binding = PluginBindingModel::where('id', $id)->where('server_id', $sid)->first();
    if (!$binding) {
      throw new ApiException("绑定码不存在");
    }
    $binding->status = 'disabled';
    $binding->save();
    PermService::clearCache($sid, (string) $binding->plugin->slug);

    AuditService::log((int) $request->uid, 'server', 'binding.disable', [
      'server_id' => $sid,
      'target_type' => 'binding',
      'target_id' => $id,
    ]);
    return success([], "已停用");
  }

  /**
   * 重新启用
   */
  public function enable(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $binding = PluginBindingModel::where('id', $id)->where('server_id', $sid)->first();
    if (!$binding) {
      throw new ApiException("绑定码不存在");
    }
    if (ServerAccountModel::find($sid)?->status !== 'active') {
      throw new ApiException("空间已被暂停, 无法启用绑定码");
    }
    $binding->status = 'active';
    $binding->save();
    PermService::clearCache($sid, (string) $binding->plugin->slug);

    AuditService::log((int) $request->uid, 'server', 'binding.enable', [
      'server_id' => $sid,
      'target_type' => 'binding',
      'target_id' => $id,
    ]);
    return success([], "已启用");
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
