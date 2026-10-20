<?php

namespace app\oauth;

use app\common\exception\ApiException;
use app\common\services\AuditService;
use app\common\services\OAuthService;
use support\Request;
use support\Response;

/**
 * OAuth2 授权端点（授权码 + PKCE 流）
 *
 * GET  /oauth/authorize        浏览器授权确认页（服务端 HTML, 在 /api 之外）
 * GET  /api/oauth/authorize-info   登录后查看待授权应用信息
 * POST /api/oauth/authorize/confirm 登录用户确认授权 → 返回带 code 的回调地址
 */
class AuthorizeController
{
  /** 只有确认页免登录（info/confirm 走 CheckLogin） */
  protected $noNeedLogin = ['page'];

  /**
   * 授权确认页（浏览器重定向目标）
   */
  public function page(Request $request): Response
  {
    $html = file_get_contents(app_path('oauth/pages/authorize.html'));
    return new Response(
      200,
      ['Content-Type' => 'text/html; charset=utf-8'],
      $html,
    );
  }

  /**
   * 待授权应用信息（确认页登录后拉取）
   */
  public function info(Request $request): Response
  {
    $clientId = (string) $request->get('client_id', '');
    $redirectUri = (string) $request->get('redirect_uri', '');
    $scope = (string) $request->get('scope', '');

    $client = OAuthService::client($clientId);
    if (!$client || $client->status !== 'active') {
      throw new ApiException('应用不存在或已被停用');
    }
    if ($client->type !== 'third_party') {
      throw new ApiException('该应用不支持网页授权（插件请使用设备码流）');
    }
    $uris = is_array($client->redirect_uris) ? $client->redirect_uris : [];
    if (!in_array($redirectUri, $uris, true)) {
      throw new ApiException('回调地址不在应用白名单内');
    }

    $scopes = OAuthService::filterScopes(
      $client,
      array_filter(explode(' ', $scope)),
    );

    return success([
      'client_id' => $client->client_id,
      'name' => $client->name,
      'type' => $client->type,
      'scopes' => $scopes,
      'scope_labels' => array_map(
        fn($s) => OAuthService::SCOPE_LABELS[$s] ?? $s,
        $scopes,
      ),
    ]);
  }

  /**
   * 用户确认授权 → 生成授权码, 返回回调地址（含 code + state）
   */
  public function confirm(Request $request): Response
  {
    $post = $request->post();
    foreach (['client_id', 'redirect_uri', 'state'] as $field) {
      if (empty($post[$field])) {
        throw new ApiException("缺少参数: {$field}");
      }
    }

    $clientId = (string) $post['client_id'];
    $redirectUri = (string) $post['redirect_uri'];
    $state = (string) $post['state'];
    $challenge = isset($post['code_challenge'])
      ? (string) $post['code_challenge']
      : null;
    $method = isset($post['code_challenge_method'])
      ? (string) $post['code_challenge_method']
      : 'plain';

    $client = OAuthService::client($clientId);
    if (
      !$client ||
      $client->status !== 'active' ||
      $client->type !== 'third_party'
    ) {
      throw new ApiException('应用不存在或不可用');
    }
    $uris = is_array($client->redirect_uris) ? $client->redirect_uris : [];
    if (!in_array($redirectUri, $uris, true)) {
      throw new ApiException('回调地址不在应用白名单内');
    }

    $requested = array_filter(explode(' ', (string) ($post['scope'] ?? '')));
    $scopes = OAuthService::filterScopes($client, $requested);

    // 授权关系 upsert（scope 并集）
    OAuthService::upsertGrant((int) $request->uid, $clientId, $scopes);

    $code = OAuthService::createAuthCode(
      (int) $request->uid,
      $clientId,
      $redirectUri,
      $scopes,
      $challenge,
      $method,
    );

    AuditService::log((int) $request->uid, 'user', 'oauth.grant', [
      'client_id' => $clientId,
      'target_type' => 'client',
      'target_id' => $clientId,
      'detail' => ['scopes' => $scopes, 'flow' => 'authorization_code'],
    ]);

    $sep = str_contains($redirectUri, '?') ? '&' : '?';
    return success([
      'redirect' =>
        $redirectUri .
        $sep .
        http_build_query(['code' => $code, 'state' => $state]),
    ]);
  }
}
