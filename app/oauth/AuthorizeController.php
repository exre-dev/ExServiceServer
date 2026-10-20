<?php

namespace app\oauth;

use OpenApi\Attributes as OA;

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
  #[OA\Get(
    path: '/oauth/authorize',
    operationId: 'showOAuthAuthorizationPage',
    summary: '显示 OAuth 授权确认页面',
    description: '免登录返回 HTML；页面自行要求登录并调用内部 authorize-info 与 authorize/confirm。查询参数由 HTML 脚本读取，服务端页面动作本身不校验参数。',
    tags: ['OAuth 页面'],
    security: [],
    parameters: [
      new OA\Parameter(
        name: 'client_id',
        in: 'query',
        required: false,
        description: 'HTML 确认页读取的应用标识。',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'redirect_uri',
        in: 'query',
        required: false,
        description: 'HTML 确认页读取的回调地址。',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'state',
        in: 'query',
        required: false,
        description: 'HTML 确认页原样传给授权确认接口的状态。',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'scope',
        in: 'query',
        required: false,
        description: '空格分隔的申请 scope。',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'code_challenge',
        in: 'query',
        required: false,
        description: '可选 PKCE challenge。',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'code_challenge_method',
        in: 'query',
        required: false,
        description: 'S256 或 plain。',
        schema: new OA\Schema(type: 'string', default: 'plain'),
      ),
    ],
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
  #[OA\Get(
    path: '/api/oauth/authorize-info',
    operationId: 'getInternalOAuthAuthorizationInfo',
    summary: '查询待授权应用信息',
    description: '需要用户会话；client_id 与 redirect_uri 虽未做独立必填检查，但缺失时应用或回调校验失败，返回 400。',
    tags: ['内部 OAuth'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'client_id',
        in: 'query',
        required: true,
        description: '必须对应 active third_party 应用。',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'redirect_uri',
        in: 'query',
        required: true,
        description: '必须精确匹配应用白名单。',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'scope',
        in: 'query',
        required: false,
        description: '与允许 scope 取交集，自动包含 user.basic。',
        schema: new OA\Schema(type: 'string'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/OAuthAuthorizationInfoResponse',
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
    x: ['audience' => 'internal'],
  )]
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
  #[OA\Post(
    path: '/api/oauth/authorize/confirm',
    operationId: 'confirmInternalOAuthAuthorization',
    summary: '确认网页授权并返回回调地址',
    description: '需要用户会话与 active third_party 应用；校验回调白名单，合并 scope 授权关系并生成 300 秒一次性授权码。响应为包含 code/state 的 redirect 字符串，不是 HTTP 302。',
    tags: ['内部 OAuth'],
    security: [['sessionAuth' => []]],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthAuthorizationConfirmRequest',
          ),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/OAuthAuthorizationConfirmRequest',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/OAuthAuthorizationConfirmResponse',
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
    x: ['audience' => 'internal'],
  )]
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
