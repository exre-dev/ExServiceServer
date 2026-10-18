<?php

namespace app\oauth;

use app\common\exception\ApiException;
use app\common\services\AuditService;
use app\common\services\OAuthService;
use app\common\services\SessionService;
use support\Request;
use support\Response;

/**
 * OAuth2 设备码流（FiveM 插件, NUI/无回调环境）
 *
 * GET  /oauth/device                 浏览器验证页（服务端 HTML, 在 /api 之外）
 * POST /api/oauth/device/init        插件发起: {client_id, client_secret} → 设备码+用户码
 * POST /api/oauth/device/token       插件轮询: {device_code, client_id, client_secret}
 * POST /api/oauth/device/verify      用户在验证页确认: {user_code}（需登录）
 */
class DeviceController
{
  /** init/token 为机器接口免登录; verify 走 CheckLogin */
  protected $noNeedLogin = ['init', 'token'];

  /**
   * 设备码验证页（玩家任意设备打开, 登录 + 输入 user_code 确认）
   */
  public function page(Request $request): Response
  {
    $html = file_get_contents(app_path('oauth/pages/device.html'));
    return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
  }

  /**
   * 插件发起设备码流程
   */
  public function init(Request $request): Response
  {
    $clientId = (string) $request->post('client_id', '');
    $clientSecret = (string) $request->post('client_secret', '');

    $client = OAuthService::client($clientId);
    if (!$client || $client->status !== 'active' || $client->type !== 'plugin') {
      return fail("应用不存在或非插件类型", 401);
    }
    if (!OAuthService::checkSecret($client, $clientSecret)) {
      return fail("client_secret 无效", 401);
    }

    $res = OAuthService::deviceInit($client);

    // ExWebsite 登录 UI 跳转地址（ssoRequestId 接收端）; 未配置时为 null
    $webBase = rtrim((string) config('userweb.base_url', ''), '/');
    $verificationWebUrl = $webBase !== ''
      ? "{$webBase}/login?ssoRequestId={$res['sso_request_id']}"
      : null;

    return success([
      'device_code' => $res['device_code'],
      'user_code' => $res['user_code'],
      'sso_request_id' => $res['sso_request_id'],
      'verification_url' => '/oauth/device',
      'verification_uri_web' => $verificationWebUrl,
      'expires_in' => OAuthService::DEVICE_TTL,
      'interval' => 5,
    ]);
  }

  /**
   * 插件轮询换取访问令牌
   * 未确认 → {"error": "authorization_pending"}
   */
  public function token(Request $request): Response
  {
    $deviceCode = (string) $request->post('device_code', '');
    $clientId = (string) $request->post('client_id', '');
    $clientSecret = (string) $request->post('client_secret', '');

    $client = OAuthService::client($clientId);
    if (!$client || $client->status !== 'active' || $client->type !== 'plugin') {
      return fail("应用不存在或非插件类型", 401);
    }
    if (!OAuthService::checkSecret($client, $clientSecret)) {
      return fail("client_secret 无效", 401);
    }

    $res = OAuthService::deviceConsume($deviceCode);
    if ($res['status'] === 'expired_token') {
      return fail("设备码已过期, 请重新发起", 400);
    }
    if ($res['status'] === 'authorization_pending') {
      return json(['error' => 'authorization_pending']);
    }
    $data = $res['data'];
    if (($data['client_id'] ?? '') !== $clientId) {
      return fail("设备码与应用不匹配", 400);
    }

    $scopes = (array) ($data['scopes'] ?? ['user.basic']);
    $session = SessionService::create((int) $data['user_id'], [
      'login_type' => 'oauth',
      'client_id' => $clientId,
      'platform' => 'fivem',
      'device_name' => 'FiveM插件 · ' . $client->name,
      'scope' => implode(' ', $scopes),
    ]);

    return success([
      'access_token' => $session['token'],
      'token_type' => 'Bearer',
      'expires_in' => $session['expires_in'],
      'scope' => implode(' ', $scopes),
    ]);
  }

  /**
   * 用户确认设备授权（验证页调用, 需登录态）
   */
  public function verify(Request $request): Response
  {
    $userCode = trim((string) $request->post('user_code', ''));
    if ($userCode === '') {
      throw new ApiException("请输入游戏内显示的 6 位验证码");
    }

    $device = OAuthService::deviceByUserCode($userCode);
    if (!$device) {
      throw new ApiException("验证码无效或已过期");
    }
    if (($device['status'] ?? '') !== 'pending') {
      throw new ApiException("该验证码已被使用");
    }

    $client = OAuthService::client((string) ($device['client_id'] ?? ''));
    if (!$client || $client->status !== 'active') {
      throw new ApiException("应用已被停用");
    }

    $scopes = is_array($client->scopes) ? $client->scopes : ['user.basic'];
    OAuthService::upsertGrant((int) $request->uid, $client->client_id, $scopes);
    OAuthService::deviceAuthorize((string) $device['device_code'], (int) $request->uid, $scopes);

    AuditService::log((int) $request->uid, 'user', 'oauth.grant', [
      'client_id' => $client->client_id,
      'target_type' => 'client',
      'target_id' => $client->client_id,
      'detail' => ['scopes' => $scopes, 'flow' => 'device_code'],
    ]);

    return success([
      'app_name' => $client->name,
    ], "授权成功, 请回到游戏完成登录");
  }
}
