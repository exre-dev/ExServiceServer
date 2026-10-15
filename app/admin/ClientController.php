<?php

namespace app\admin;

use app\common\exception\ApiException;
use app\common\model\OAuthClientModel;
use app\common\model\ServerAccountModel;
use app\common\services\AuditService;
use app\common\services\SessionService;

use support\Request;
use support\Response;

/**
 * 平台管理端-第三方应用（SSO client, 唯一注册入口, 空间管理员与用户无管理权）
 */
class ClientController
{
  /** scope 字典（蓝图 §4.6） */
  private const SCOPES = ['user.basic', 'user.email', 'idents.read', 'perms.read', 'servers.read'];

  /**
   * 应用列表
   */
  public function index(Request $request): Response
  {
    $page = max(1, (int) $request->get('page', 1));
    $pageSize = min(50, max(1, (int) $request->get('page_size', 10)));
    $type = (string) $request->get('type', '');
    $status = (string) $request->get('status', '');

    $q = OAuthClientModel::with('server:id,name,slug');
    if ($type !== '') {
      $q->where('type', $type);
    }
    if ($status !== '') {
      $q->where('status', $status);
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    return success([
      'list' => $pager->items(),
      'total' => $pager->total(),
      'scope_dict' => self::SCOPES,
    ]);
  }

  /**
   * 注册应用（client_secret 明文仅本次返回）
   */
  public function create(Request $request): Response
  {
    $post = $request->post();
    if (hasMissingKeys($post, ["name", "type"])) {
      throw new ApiException("参数不匹配");
    }

    $name = trim((string) $post['name']);
    $type = (string) $post['type'];
    if ($name === '' || !in_array($type, ['third_party', 'plugin'], true)) {
      throw new ApiException("名称必填, type 为 third_party/plugin");
    }

    $serverId = null;
    if ($type === 'plugin') {
      $serverId = (int) ($post['server_id'] ?? 0);
      if ($serverId <= 0 || !ServerAccountModel::where('id', $serverId)->where('status', 'active')->first()) {
        throw new ApiException("plugin 类应用必须归属一个 active 状态的空间");
      }
    }

    $redirectUris = $post['redirect_uris'] ?? [];
    if (!is_array($redirectUris)) {
      $redirectUris = [];
    }
    foreach ($redirectUris as $uri) {
      if (!filter_var($uri, FILTER_VALIDATE_URL)) {
        throw new ApiException("回调地址不合法: {$uri}");
      }
    }

    $scopes = $post['scopes'] ?? [];
    if (!is_array($scopes)) {
      $scopes = [];
    }
    $invalid = array_diff($scopes, self::SCOPES);
    if ($invalid) {
      throw new ApiException("不支持的 scope: " . implode(',', $invalid));
    }
    if (!in_array('user.basic', $scopes, true)) {
      $scopes[] = 'user.basic'; // 必选项
    }

    $clientId = bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(24));

    $client = new OAuthClientModel();
    $client->client_id = $clientId;
    $client->name = $name;
    $client->type = $type;
    $client->server_id = $serverId;
    $client->client_secret_hash = hash('sha256', $secret);
    $client->redirect_uris = array_values($redirectUris);
    $client->scopes = array_values($scopes);
    $client->status = 'active';
    $client->note = (string) ($post['note'] ?? '');
    $client->created_by = (int) $request->uid;
    $client->save();

    AuditService::log((int) $request->uid, 'platform', 'client.create', [
      'server_id' => $serverId,
      'client_id' => $clientId,
      'target_type' => 'client',
      'target_id' => $client->id,
      'detail' => ['type' => $type, 'scopes' => $scopes],
    ]);

    // 明文 secret 只显示这一次
    return success([
      'id' => $client->id,
      'client_id' => $clientId,
      'client_secret' => $secret,
    ], "应用已注册, 密钥仅本次显示请立即保存");
  }

  /**
   * 重置密钥（旧 token 全部失效, 新 secret 仅本次返回）
   */
  public function secret_reset(Request $request, int $id): Response
  {
    $client = OAuthClientModel::find($id);
    if (!$client) {
      throw new ApiException("应用不存在");
    }

    $secret = bin2hex(random_bytes(24));
    $client->client_secret_hash = hash('sha256', $secret);
    $client->save();

    // 吊销该应用名下全部会话
    $revoked = SessionService::revokeAllForClient($client->client_id);

    AuditService::log((int) $request->uid, 'platform', 'client.secret_reset', [
      'client_id' => $client->client_id,
      'target_type' => 'client',
      'target_id' => $id,
      'detail' => ['revoked_sessions' => $revoked],
    ]);
    return success([
      'client_id' => $client->client_id,
      'client_secret' => $secret,
    ], "密钥已重置（下线 {$revoked} 个会话）, 新密钥仅本次显示");
  }

  /**
   * 停用/启用应用（停用即全部 token 失效）
   */
  public function status(Request $request, int $id): Response
  {
    $client = OAuthClientModel::find($id);
    if (!$client) {
      throw new ApiException("应用不存在");
    }

    $status = (string) $request->post('status', '');
    if (!in_array($status, ['active', 'suspended'], true)) {
      throw new ApiException("非法状态值");
    }

    $client->status = $status;
    $client->save();

    $revoked = 0;
    if ($status === 'suspended') {
      $revoked = SessionService::revokeAllForClient($client->client_id);
    }

    AuditService::log((int) $request->uid, 'platform', 'client.status', [
      'client_id' => $client->client_id,
      'target_type' => 'client',
      'target_id' => $id,
      'detail' => ['status' => $status, 'revoked_sessions' => $revoked],
    ]);
    return success([], $status === 'suspended' ? "应用已停用（下线 {$revoked} 个会话）" : "应用已启用");
  }
}
