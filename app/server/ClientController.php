<?php

namespace app\server;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\AuthSessionModel;
use app\common\model\OAuthClientModel;
use app\common\model\ServerClientLinkModel;
use app\common\model\ServerMemberModel;
use app\common\services\AuditService;

use support\Redis;
use support\Request;
use support\Response;

/**
 * 空间管理端-应用授权（查看挂在本空间的插件类应用, 仅有断开/恢复权, 无注册权）
 */
class ClientController
{
  /**
   * 挂在本空间的应用列表（type=plugin 且归属本空间）
   */
  public function index(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $clients = OAuthClientModel::where('type', 'plugin')
      ->where('server_id', $sid)
      ->orderByDesc('id')
      ->get();

    $links = ServerClientLinkModel::where('server_id', $sid)
      ->get()
      ->keyBy('client_id');

    $list = $clients->map(function ($c) use ($links) {
      $link = $links->get($c->client_id);
      return [
        'id' => (int) $c->id,
        'client_id' => $c->client_id,
        'name' => $c->name,
        'scopes' => $c->scopes,
        'client_status' => $c->status,
        'link_status' => $link ? $link->status : 'active',
        'disconnected_time' => $link?->disconnected_time,
      ];
    });

    return success(['list' => $list]);
  }

  /**
   * 断开应用（该应用名下本空间成员的会话立即失效, 不再能获取本空间数据）
   */
  public function disconnect(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $clientId = (string) $request->post('client_id', '');
    $client = OAuthClientModel::where('client_id', $clientId)->where('server_id', $sid)->first();
    if (!$client) {
      throw new ApiException("应用不存在或不属于本空间");
    }

    $link = ServerClientLinkModel::where('server_id', $sid)->where('client_id', $clientId)->first();
    if (!$link) {
      $link = new ServerClientLinkModel();
      $link->server_id = $sid;
      $link->client_id = $clientId;
      $link->linked_time = date('Y-m-d H:i:s');
    }
    $link->status = 'disconnected';
    $link->disconnected_time = date('Y-m-d H:i:s');
    $link->save();

    // 下线该应用名下本空间成员的会话
    $memberIds = ServerMemberModel::where('server_id', $sid)->pluck('user_id')->all();
    $revoked = 0;
    if ($memberIds) {
      $sessions = AuthSessionModel::where('client_id', $clientId)
        ->whereIn('user_id', $memberIds)
        ->where('status', 'active')
        ->get();
      foreach ($sessions as $s) {
        Redis::del('sess:' . $s->id);
        $s->status = 'revoked';
        $s->updated_time = date('Y-m-d H:i:s');
        $s->save();
        $revoked++;
      }
    }

    AuditService::log((int) $request->uid, 'server', 'client.disconnect', [
      'server_id' => $sid,
      'client_id' => $clientId,
      'target_type' => 'client',
      'target_id' => $client->id,
      'detail' => ['revoked_sessions' => $revoked],
    ]);
    return success([], "已断开（下线 {$revoked} 个相关会话）");
  }

  /**
   * 恢复应用
   */
  public function connect(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $clientId = (string) $request->post('client_id', '');
    $client = OAuthClientModel::where('client_id', $clientId)->where('server_id', $sid)->first();
    if (!$client) {
      throw new ApiException("应用不存在或不属于本空间");
    }

    $link = ServerClientLinkModel::where('server_id', $sid)->where('client_id', $clientId)->first();
    if ($link) {
      $link->status = 'active';
      $link->save();
    }

    AuditService::log((int) $request->uid, 'server', 'client.connect', [
      'server_id' => $sid,
      'client_id' => $clientId,
      'target_type' => 'client',
      'target_id' => $client->id,
    ]);
    return success([], "已恢复");
  }
}
