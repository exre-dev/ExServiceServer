<?php

namespace app\oauth;

use OpenApi\Attributes as OA;

use app\common\services\OAuthService;
use app\common\services\SessionService;
use support\Request;
use support\Response;

/**
 * OAuth2 令牌端点（机器调用, 客户端凭证鉴权）
 *
 * POST /api/oauth/token  grant_type=authorization_code
 *   {code, client_id, client_secret, redirect_uri, code_verifier}
 * 返回 {access_token, token_type: Bearer, expires_in, scope}（v1 不发 refresh token）
 */
class TokenController
{
  /** 机器接口不走登录 */
  protected $noNeedLogin = '*';

  #[OA\Post(
    path: '/api/oauth/token',
    operationId: 'exchangeOAuthAuthorizationCode',
    summary: '使用授权码换取访问令牌',
    description: '客户端凭证在请求 body 中传递，不支持 HTTP Basic。grant_type 省略时为 authorization_code；授权码有效 300 秒、一次性消费；redirect_uri 与 PKCE 不匹配时仍已消费授权码。不签发 refresh_token。',
    tags: ['OAuth 授权'],
    security: [],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthAuthorizationCodeRequest',
          ),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthAuthorizationCodeRequest',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/OAuthTokenResponse',
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
  public function issue(Request $request): Response
  {
    $grantType = (string) $request->post('grant_type', 'authorization_code');
    if ($grantType !== 'authorization_code') {
      return fail(
        '不支持的 grant_type（设备码流请用 /oauth/device/token）',
        400,
      );
    }

    $code = (string) $request->post('code', '');
    $clientId = (string) $request->post('client_id', '');
    $clientSecret = (string) $request->post('client_secret', '');
    $redirectUri = (string) $request->post('redirect_uri', '');
    $verifier = (string) $request->post('code_verifier', '');

    // 客户端凭证
    $client = OAuthService::client($clientId);
    if (
      !$client ||
      $client->status !== 'active' ||
      !OAuthService::checkSecret($client, $clientSecret)
    ) {
      return fail('client 凭证无效', 401);
    }

    // 授权码（一次性）
    $data = OAuthService::consumeAuthCode($code);
    if (!$data || ($data['client_id'] ?? '') !== $clientId) {
      return fail('授权码无效或已使用', 400);
    }
    if (($data['redirect_uri'] ?? '') !== $redirectUri) {
      return fail('redirect_uri 与授权请求不一致', 400);
    }

    // PKCE
    $challenge = (string) ($data['code_challenge'] ?? '');
    if ($challenge !== '') {
      if (
        $verifier === '' ||
        !OAuthService::pkceMatch(
          $challenge,
          (string) ($data['code_challenge_method'] ?? 'plain'),
          $verifier,
        )
      ) {
        return fail('PKCE 校验失败', 400);
      }
    }

    // 签发会话化访问令牌
    $scopes = (array) ($data['scopes'] ?? ['user.basic']);
    $session = SessionService::create((int) $data['user_id'], [
      'login_type' => 'oauth',
      'client_id' => $clientId,
      'platform' => 'third_party',
      'device_name' => '第三方平台 · ' . $client->name,
      'scope' => implode(' ', $scopes),
    ]);

    return success([
      'access_token' => $session['token'],
      'token_type' => 'Bearer',
      'expires_in' => $session['expires_in'],
      'scope' => implode(' ', $scopes),
    ]);
  }
}
