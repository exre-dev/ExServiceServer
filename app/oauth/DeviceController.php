<?php

namespace app\oauth;

use OpenApi\Attributes as OA;

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
  #[OA\Get(
    path: '/oauth/device',
    operationId: 'showOAuthDevicePage',
    summary: '显示设备码验证页面',
    description: '当前实现 page 不在 noNeedLogin 列表中，因此全局 CheckLogin 先要求有效 Bearer 会话；缺失或无效返回 JSON 401，用户封禁返回 403，成功才返回 HTML。页面随后使用六位用户码调用内部 device/verify。',
    tags: ['OAuth 页面'],
    security: [['sessionAuth' => []]],
    responses: [
      new OA\Response(
        response: 200,
        description: '浏览器 HTML 页面。',
        content: new OA\MediaType(
          mediaType: 'text/html',
          schema: new OA\Schema(type: 'string'),
        ),
      ),
      new OA\Response(
        response: 401,
        description: '登录态或客户端凭证无效、访问令牌无效或授权已撤销。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '身份被禁用、绑定码缺失或无效，或请求频率受限。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'public'],
  )]
  public function page(Request $request): Response
  {
    $html = file_get_contents(app_path('oauth/pages/device.html'));
    return new Response(
      200,
      ['Content-Type' => 'text/html; charset=utf-8'],
      $html,
    );
  }

  /**
   * 插件发起设备码流程
   */
  #[OA\Post(
    path: '/api/oauth/device/init',
    operationId: 'createOAuthDeviceAuthorization',
    summary: '发起插件设备码授权',
    description: '仅 active plugin 应用可用。client_id/client_secret 在请求 body 中传递；返回设备码、六位用户码及供用户网站使用的 sso_request_id，有效 600 秒。',
    tags: ['OAuth 授权'],
    security: [],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthDeviceInitRequest',
          ),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthDeviceInitRequest',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/OAuthDeviceInitResponse',
        ),
      ),
      new OA\Response(
        response: 401,
        description: '登录态或客户端凭证无效、访问令牌无效或授权已撤销。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '身份被禁用、绑定码缺失或无效，或请求频率受限。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'public'],
  )]
  public function init(Request $request): Response
  {
    $clientId = (string) $request->post('client_id', '');
    $clientSecret = (string) $request->post('client_secret', '');

    $client = OAuthService::client($clientId);
    if (
      !$client ||
      $client->status !== 'active' ||
      $client->type !== 'plugin'
    ) {
      return fail('应用不存在或非插件类型', 401);
    }
    if (!OAuthService::checkSecret($client, $clientSecret)) {
      return fail('client_secret 无效', 401);
    }

    $res = OAuthService::deviceInit($client);

    // ExWebsite 登录 UI 跳转地址（ssoRequestId 接收端）; 未配置时为 null
    $webBase = rtrim((string) config('userweb.base_url', ''), '/');
    $verificationWebUrl =
      $webBase !== ''
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
  #[OA\Post(
    path: '/api/oauth/device/token',
    operationId: 'pollOAuthDeviceToken',
    summary: '轮询设备授权并换取访问令牌',
    description: '仅 active plugin 应用可用，客户端凭证在请求 body 中传递。等待用户确认时 HTTP 200 返回裸 {error: authorization_pending}；授权后一次性消费设备码并返回 {msg,data} 令牌信封。过期、重复消费、应用不匹配为 400；建议按初始化返回的 5 秒间隔轮询，当前实现未强制间隔。',
    tags: ['OAuth 授权'],
    security: [],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthDeviceTokenRequest',
          ),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthDeviceTokenRequest',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          oneOf: [
            new OA\Schema(ref: '#/components/schemas/OAuthTokenResponse'),
            new OA\Schema(
              ref: '#/components/schemas/OAuthAuthorizationPending',
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
        description: '登录态或客户端凭证无效、访问令牌无效或授权已撤销。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '身份被禁用、绑定码缺失或无效，或请求频率受限。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'public'],
  )]
  public function token(Request $request): Response
  {
    $deviceCode = (string) $request->post('device_code', '');
    $clientId = (string) $request->post('client_id', '');
    $clientSecret = (string) $request->post('client_secret', '');

    $client = OAuthService::client($clientId);
    if (
      !$client ||
      $client->status !== 'active' ||
      $client->type !== 'plugin'
    ) {
      return fail('应用不存在或非插件类型', 401);
    }
    if (!OAuthService::checkSecret($client, $clientSecret)) {
      return fail('client_secret 无效', 401);
    }

    $res = OAuthService::deviceConsume($deviceCode, $clientId);
    if ($res['status'] === 'expired_token') {
      return fail('设备码已过期, 请重新发起', 400);
    }
    if ($res['status'] === 'authorization_pending') {
      return json(['error' => 'authorization_pending']);
    }
    if ($res['status'] === 'client_mismatch') {
      throw new ApiException('设备码与应用不匹配');
    }
    $data = $res['data'];
    if (($data['client_id'] ?? '') !== $clientId) {
      return fail('设备码与应用不匹配', 400);
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
  #[OA\Post(
    path: '/api/oauth/device/verify',
    operationId: 'verifyInternalOAuthDevice',
    summary: '确认六位设备用户码',
    description: '需要用户会话；校验用户码和应用状态，创建授权关系并原子确认设备。无效、过期或已使用用户码返回 400，并发状态变化返回 409。',
    tags: ['内部 OAuth'],
    security: [['sessionAuth' => []]],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthDeviceVerifyRequest',
          ),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthDeviceVerifyRequest',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/OAuthDeviceVerifyResponse',
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态或客户端凭证无效、访问令牌无效或授权已撤销。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '身份被禁用、绑定码缺失或无效，或请求频率受限。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 409,
        description: '设备请求已完成、已失效、被其他用户处理或应用已停用。',
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
  public function verify(Request $request): Response
  {
    $userCode = trim((string) $request->post('user_code', ''));
    if ($userCode === '') {
      throw new ApiException('请输入游戏内显示的 6 位验证码');
    }

    $device = OAuthService::deviceByUserCode($userCode);
    if (!$device) {
      throw new ApiException('验证码无效或已过期');
    }
    if (($device['status'] ?? '') !== 'pending') {
      throw new ApiException('该验证码已被使用');
    }

    $client = OAuthService::client((string) ($device['client_id'] ?? ''));
    if (!$client || $client->status !== 'active') {
      throw new ApiException('应用已被停用');
    }

    $scopes = is_array($client->scopes) ? $client->scopes : ['user.basic'];
    OAuthService::upsertGrant((int) $request->uid, $client->client_id, $scopes);
    if (
      !OAuthService::deviceAuthorize(
        (string) $device['device_code'],
        (int) $request->uid,
        $scopes,
      )
    ) {
      throw new ApiException('设备请求已失效或已被处理, 请重新发起', 409);
    }

    AuditService::log((int) $request->uid, 'user', 'oauth.grant', [
      'client_id' => $client->client_id,
      'target_type' => 'client',
      'target_id' => $client->client_id,
      'detail' => ['scopes' => $scopes, 'flow' => 'device_code'],
    ]);

    return success(
      [
        'app_name' => $client->name,
      ],
      '授权成功, 请回到游戏完成登录',
    );
  }
}
