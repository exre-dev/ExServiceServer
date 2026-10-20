<?php

namespace app\oauth;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\model\UserModel;
use app\common\services\AuditService;
use app\common\services\CaptchaService;
use app\common\services\OAuthService;
use support\Request;
use support\Response;

/**
 * SSO 接收端（ExWebsite user-web 登录 UI, /api/auth/sso/*）
 *
 * ssoRequestId 由 webman OAuth 设备码流签发（device/init 返回）：
 * 插件/客户端拿到 sso_request_id 后把用户送往 user-web /login?ssoRequestId=xxx，
 * 用户在此三端点上完成"凭据登录（login）"或"已有会话确认（confirm）"，
 * 客户端继续轮询 /api/oauth/device/token 即拿到访问令牌——闭环不走跳转回调。
 */
class SsoController
{
  /** inspect/login 为浏览器侧免登录; confirm 依赖 CheckLogin 的 bearer 会话 */
  protected $noNeedLogin = ['inspect', 'login'];

  /**
   * 查询 SSO 请求状态
   */
  #[OA\Post(
    path: '/api/auth/sso/inspect',
    operationId: 'inspectInternalSsoRequest',
    summary: '查询用户网站 SSO 请求状态',
    description: '免登录；检查 device/init 返回的 sso_request_id。不存在、格式不匹配或过期均返回 404；时间为 Unix 毫秒。',
    tags: ['内部 SSO'],
    security: [],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(ref: '#/components/schemas/SsoRequest'),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(ref: '#/components/schemas/SsoRequest'),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/SsoInspectResponse',
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
        response: 404,
        description: 'SSO 请求不存在或已过期。',
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
  public function inspect(Request $request): Response
  {
    $ssoRequestId = (string) $request->post('ssoRequestId', '');
    $device = OAuthService::deviceBySsoRequestId($ssoRequestId);
    if ($device === null) {
      throw new ApiException('SSO 请求不存在或已过期', 404);
    }
    return success([
      'ssoRequestId' => $ssoRequestId,
      'status' =>
        ($device['data']['status'] ?? '') === 'authorized'
          ? 'completed'
          : 'pending',
      'expiresAt' => (time() + max(0, $device['ttl'])) * 1000,
      'serverTime' => time() * 1000,
    ]);
  }

  /**
   * 凭据登录并完成 SSO 请求（用户在 user-web 界面输入账号密码）
   * 注意: 与普通登录不同, 此处不签发本地会话令牌——仅确认 SSO 请求身份
   */
  #[OA\Post(
    path: '/api/auth/sso/login',
    operationId: 'loginInternalSsoRequest',
    summary: '使用邮箱密码完成 SSO 授权',
    description: '免登录；可选图片验证码传入后会校验。凭据错误为 400，用户禁用为 403，SSO 请求不存在或过期为 404，已完成、并发处理或应用停用为 409。本动作只确认设备请求，不签发浏览器本地会话；插件继续轮询 device/token。',
    tags: ['内部 SSO'],
    security: [],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(ref: '#/components/schemas/SsoLoginRequest'),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(ref: '#/components/schemas/SsoLoginRequest'),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/SsoCompletedResponse',
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        description: '身份被禁用、绑定码缺失或无效，或请求频率受限。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 404,
        description: 'SSO 请求不存在或已过期。',
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
        response: 422,
        description: '图片验证码不正确或已过期。',
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
  public function login(Request $request): Response
  {
    $post = $request->post();
    if (
      empty($post) ||
      hasMissingKeys($post, ['ssoRequestId', 'email', 'password'])
    ) {
      throw new ApiException('参数不匹配');
    }

    $device = OAuthService::deviceBySsoRequestId(
      (string) $post['ssoRequestId'],
    );
    if ($device === null) {
      throw new ApiException('SSO 请求不存在或已过期', 404);
    }
    if (($device['data']['status'] ?? '') !== 'pending') {
      throw new ApiException('该 SSO 请求已完成', 409);
    }

    // 图片验证码（契约 SsoPasswordRequest.captcha, 可选校验）
    CaptchaService::checkFromRequest($request->post('captcha'));

    $email = trim((string) $post['email']);
    $user = UserModel::where('email', $email)->first();
    if (
      !$user ||
      !password_verify(
        (string) $post['password'],
        (string) $user->password_hash,
      )
    ) {
      throw new ApiException('邮箱或密码错误'); // 400, 页面 mapError(400) 映射凭据文案
    }
    if ((int) $user->ban !== 0) {
      throw new ApiException('当前账户已经被禁用', 403);
    }

    return $this->authorize($device, (int) $user->id, 'sso.login');
  }

  /**
   * 用已有会话确认 SSO 请求（bearer, CheckLogin 注入 uid）
   */
  #[OA\Post(
    path: '/api/auth/sso/confirm',
    operationId: 'confirmInternalSsoRequest',
    summary: '使用当前会话确认 SSO 授权',
    description: '使用 CheckLogin 注入的当前用户身份确认设备请求。请求不存在或过期为 404；已完成、并发处理或应用停用为 409。插件继续轮询 device/token 获取 OAuth 令牌。',
    tags: ['内部 SSO'],
    security: [['sessionAuth' => []]],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(ref: '#/components/schemas/SsoRequest'),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(ref: '#/components/schemas/SsoRequest'),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/SsoCompletedResponse',
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
        response: 404,
        description: 'SSO 请求不存在或已过期。',
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
  public function confirm(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ['ssoRequestId'])) {
      throw new ApiException('参数不匹配');
    }

    $device = OAuthService::deviceBySsoRequestId(
      (string) $post['ssoRequestId'],
    );
    if ($device === null) {
      throw new ApiException('SSO 请求不存在或已过期', 404);
    }
    if (($device['data']['status'] ?? '') !== 'pending') {
      throw new ApiException('该 SSO 请求已完成', 409);
    }

    return $this->authorize($device, (int) $request->uid, 'sso.confirm');
  }

  /**
   * 授权设备请求（grant + 标记 + 审计 + 契约响应）
   */
  private function authorize(
    array $device,
    int $userId,
    string $action,
  ): Response {
    $clientId = (string) ($device['data']['client_id'] ?? '');
    $client = OAuthService::client($clientId);
    if (!$client || $client->status !== 'active') {
      throw new ApiException('应用已被停用', 409);
    }

    $scopes = is_array($client->scopes) ? $client->scopes : ['user.basic'];
    OAuthService::upsertGrant($userId, $client->client_id, $scopes);
    if (
      !OAuthService::deviceAuthorize($device['device_code'], $userId, $scopes)
    ) {
      throw new ApiException('SSO 请求已失效或已被处理, 请重新发起', 409);
    }

    AuditService::log($userId, 'user', 'oauth.grant', [
      'client_id' => $client->client_id,
      'target_type' => 'client',
      'target_id' => $client->client_id,
      'detail' => ['scopes' => $scopes, 'flow' => 'sso_request'],
    ]);

    return success([
      'status' => 'completed',
      'ssoRequestId' => (string) ($device['data']['sso_request_id'] ?? ''),
    ]);
  }
}
