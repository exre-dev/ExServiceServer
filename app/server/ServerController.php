<?php

namespace app\server;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\ServerAccountModel;
use app\common\model\ServerMemberModel;
use app\common\services\AuditService;

use support\Request;
use support\Response;

/**
 * 空间管理端-空间本体（我的空间/概览/设置）
 */
class ServerController
{
  /**
   * 我管理的空间（owner/admin, 供前端空间切换器）
   * 注意: 此接口在 {sid} 路由组之外, 不走 ServerMember 中间件
   */
  public function mine(Request $request): Response
  {
    $rows = ServerMemberModel::where('user_id', $request->uid)
      ->whereIn('role_key', ['owner', 'admin'])
      ->where('status', 'active')
      ->with('server:id,name,slug,status,logo')
      ->get();

    $list = $rows
      ->filter(fn($r) => $r->server && $r->server->status === 'active')
      ->map(
        fn($r) => [
          'id' => (int) $r->server->id,
          'name' => $r->server->name,
          'slug' => $r->server->slug,
          'logo' => $r->server->logo,
          'my_role' => $r->role_key,
        ],
      )
      ->values();

    return success(['list' => $list]);
  }

  /**
   * 空间概览（任意 active 成员可看）
   */
  public function overview(Request $request, int $sid): Response
  {
    $server = ServerAccountModel::find($sid);
    if (!$server) {
      throw new ApiException('空间不存在');
    }

    return success([
      'server' => $server,
      'my_role' => (string) $request->server_role,
      'member_count' => ServerMemberModel::where('server_id', $sid)
        ->where('status', 'active')
        ->count(),
      'binding_count' => \app\common\model\PluginBindingModel::where(
        'server_id',
        $sid,
      )->count(),
      'ident_count' => \app\common\model\UserIdentModel::where(
        'bound_server_id',
        $sid,
      )->count(),
    ]);
  }

  /**
   * 更新空间设置（仅 Owner）
   */
  public function settings(Request $request, int $sid): Response
  {
    ServerMember::assertOwner($request);

    $server = ServerAccountModel::find($sid);
    if (!$server) {
      throw new ApiException('空间不存在');
    }

    $post = $request->post();
    foreach (['name', 'description', 'contact_qq', 'logo'] as $field) {
      if (array_key_exists($field, $post)) {
        $server->$field = (string) $post[$field];
      }
    }
    $server->save();

    AuditService::log((int) $request->uid, 'server', 'server.settings', [
      'server_id' => $sid,
      'target_type' => 'server',
      'target_id' => $sid,
    ]);
    return success();
  }
}
