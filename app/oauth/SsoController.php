<?php

namespace app\oauth;

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
  public function inspect(Request $request): Response
  {
    $ssoRequestId = (string) $request->post('ssoRequestId', '');
    $device = OAuthService::deviceBySsoRequestId($ssoRequestId);
    if ($device === null) {
      throw new ApiException("SSO 请求不存在或已过期", 404);
    }
    return success([
      'ssoRequestId' => $ssoRequestId,
      'status' => ($device['data']['status'] ?? '') === 'authorized' ? 'completed' : 'pending',
      'expiresAt' => time() + max(0, $device['ttl']),
      'serverTime' => time(),
    ]);
  }

  /**
   * 凭据登录并完成 SSO 请求（用户在 user-web 界面输入账号密码）
   * 注意: 与普通登录不同, 此处不签发本地会话令牌——仅确认 SSO 请求身份
   */
  public function login(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["ssoRequestId", "email", "password"])) {
      throw new ApiException("参数不匹配");
    }

    $device = OAuthService::deviceBySsoRequestId((string) $post['ssoRequestId']);
    if ($device === null) {
      throw new ApiException("SSO 请求不存在或已过期", 404);
    }
    if (($device['data']['status'] ?? '') !== 'pending') {
      throw new ApiException("该 SSO 请求已完成", 409);
    }

    // 图片验证码（契约 SsoPasswordRequest.captcha, 可选校验）
    CaptchaService::checkFromRequest($request->post('captcha'));

    $email = trim((string) $post['email']);
    $user = UserModel::where('email', $email)->first();
    if (!$user || !password_verify((string) $post['password'], (string) $user->password_hash)) {
      throw new ApiException("邮箱或密码错误"); // 400, 页面 mapError(400) 映射凭据文案
    }
    if ((int) $user->ban !== 0) {
      throw new ApiException("当前账户已经被禁用", 403);
    }

    return $this->authorize($device, (int) $user->id, 'sso.login');
  }

  /**
   * 用已有会话确认 SSO 请求（bearer, CheckLogin 注入 uid）
   */
  public function confirm(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["ssoRequestId"])) {
      throw new ApiException("参数不匹配");
    }

    $device = OAuthService::deviceBySsoRequestId((string) $post['ssoRequestId']);
    if ($device === null) {
      throw new ApiException("SSO 请求不存在或已过期", 404);
    }
    if (($device['data']['status'] ?? '') !== 'pending') {
      throw new ApiException("该 SSO 请求已完成", 409);
    }

    return $this->authorize($device, (int) $request->uid, 'sso.confirm');
  }

  /**
   * 授权设备请求（grant + 标记 + 审计 + 契约响应）
   */
  private function authorize(array $device, int $userId, string $action): Response
  {
    $clientId = (string) ($device['data']['client_id'] ?? '');
    $client = OAuthService::client($clientId);
    if (!$client || $client->status !== 'active') {
      throw new ApiException("应用已被停用", 409);
    }

    $scopes = is_array($client->scopes) ? $client->scopes : ['user.basic'];
    OAuthService::upsertGrant($userId, $client->client_id, $scopes);
    OAuthService::deviceAuthorize($device['device_code'], $userId, $scopes);

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
