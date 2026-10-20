<?php

namespace app\open;

use app\common\exception\ApiException;
use app\common\model\ServerMemberModel;
use app\common\model\UserIdentModel;
use app\common\model\UserModel;
use app\common\services\AuditService;
use app\common\services\PermService;
use support\Request;
use support\Response;

/**
 * 开放 API-OAuth 用户信息（第三方平台/插件凭 Bearer 访问令牌调用）
 *
 * GET  /api/open/userinfo            按 scope 过滤返回用户身份数据
 * POST /api/open/ident/bind-self     玩家自绑定游戏标识（插件 SSO 通道, 绑定即入空间）
 */
class OAuthUserController
{
  /** 鉴权走 OAuthTokenAuth 中间件, 不走登录 */
  protected $noNeedLogin = '*';

  /**
   * 用户信息（scope 硬过滤）
   */
  public function userinfo(Request $request): Response
  {
    $user = UserModel::find($request->oauth_user_id);
    if (!$user) {
      throw new ApiException('用户不存在');
    }
    $scopes = (array) $request->oauth_scopes;

    $data = [];
    // user.basic（必选）
    $data['id'] = (int) $user->id;
    $data['username'] = $user->username;
    $data['avatar'] = $user->avatar;

    if (in_array('user.email', $scopes, true)) {
      $data['email'] = $user->email;
    }

    if (in_array('idents.read', $scopes, true)) {
      $data['idents'] = UserIdentModel::where('user_id', $user->id)
        ->get(['ident_type', 'ident_value'])
        ->map(fn($r) => ['type' => $r->ident_type, 'value' => $r->ident_value])
        ->all();
    }

    if (in_array('servers.read', $scopes, true)) {
      $data['servers'] = ServerMemberModel::where('user_id', $user->id)
        ->where('status', 'active')
        ->with('server:id,name,slug')
        ->get()
        ->map(
          fn($r) => [
            'name' => $r->server?->name,
            'slug' => $r->server?->slug,
            'role' => $r->role_key,
          ],
        )
        ->all();
    }

    if (in_array('perms.read', $scopes, true)) {
      // 用户在所有已加入空间里的插件功能权限
      $perms = [];
      $memberships = ServerMemberModel::where('user_id', $user->id)
        ->where('status', 'active')
        ->with('server:id,slug')
        ->get();
      foreach ($memberships as $m) {
        $bindings = \app\common\model\PluginBindingModel::where(
          'server_id',
          $m->server_id,
        )
          ->with('plugin:id,slug')
          ->where('status', 'active')
          ->get();
        foreach ($bindings as $b) {
          if (!$b->plugin) {
            continue;
          }
          $perm = PermService::canUse(
            (int) $user->id,
            (int) $m->server_id,
            (string) $b->plugin->slug,
          );
          if ($perm !== null) {
            $perms[] = [
              'server' => $m->server?->slug,
              'plugin' => $b->plugin->slug,
              'allowed' => $perm['allowed'],
              'features' => $perm['features'],
            ];
          }
        }
      }
      $data['perms'] = $perms;
    }

    return success($data);
  }

  /**
   * 玩家自绑定（插件拿到用户令牌后调用: 把当前游戏 ident 绑到该用户;
   * 插件类应用自动让用户加入归属空间）
   */
  public function bind_self(Request $request): Response
  {
    $scopes = (array) $request->oauth_scopes;
    if (!in_array('idents.read', $scopes, true)) {
      throw new ApiException('缺少 idents.read 权限');
    }

    $ident = trim((string) $request->post('ident', ''));
    $identType =
      (string) ($request->post('ident_type', 'fivem_license') ??
        'fivem_license');
    if (
      $ident === '' ||
      !in_array($identType, ['fivem_license', 'steam', 'discord', 'qq'], true)
    ) {
      throw new ApiException(
        'ident 必填, ident_type 为 fivem_license/steam/discord/qq',
      );
    }

    $uid = (int) $request->oauth_user_id;

    $exists = UserIdentModel::where('ident_type', $identType)
      ->where('ident_value', $ident)
      ->first();
    if ($exists) {
      if ((int) $exists->user_id === $uid) {
        return success([], '已绑定当前账号, 无需重复绑定');
      }
      throw new ApiException('该游戏标识已绑定其他账号');
    }

    // 插件类应用: 自动加入归属空间
    $client = $request->oauth_client;
    $joinServerId = null;
    if ($client && $client->type === 'plugin' && $client->server_id) {
      $member = ServerMemberModel::where('server_id', $client->server_id)
        ->where('user_id', $uid)
        ->first();
      if ($member && $member->status === 'banned') {
        throw new ApiException('你已被该服务器空间禁用, 无法绑定');
      }
      if (!$member) {
        $member = new ServerMemberModel();
        $member->server_id = $client->server_id;
        $member->user_id = $uid;
        $member->role_key = 'member';
        $member->source = 'bind';
        $member->joined_time = date('Y-m-d H:i:s');
      }
      $member->status = 'active';
      $member->save();
      $joinServerId = (int) $client->server_id;
    }

    $row = new UserIdentModel();
    $row->user_id = $uid;
    $row->ident_type = $identType;
    $row->ident_value = $ident;
    $row->bound_server_id = $joinServerId;
    $row->created_time = date('Y-m-d H:i:s');
    $row->save();

    AuditService::log($uid, 'user', 'ident.bind', [
      'server_id' => $joinServerId,
      'client_id' => $client?->client_id,
      'target_type' => 'ident',
      'target_id' => $row->id,
      'detail' => [
        'channel' => 'sso_bind_self',
        'ident' => '...' . substr($ident, -8),
      ],
    ]);

    return success(
      [
        'ident_tail' => '...' . substr($ident, -8),
        'joined_server' => (bool) $joinServerId,
      ],
      '绑定成功',
    );
  }
}
