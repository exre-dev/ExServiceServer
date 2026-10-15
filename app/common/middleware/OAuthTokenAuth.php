<?php

namespace app\common\middleware;

use app\common\model\OAuthClientModel;
use app\common\model\OAuthGrantModel;
use app\common\services\SessionService;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * OAuth 访问令牌鉴权（/api/open/userinfo、/api/open/ident/bind-self 用）
 *
 * 校验链: Bearer JWT 有效 → 会话 active 且为 oauth 类型 → 应用 active → 授权关系 active。
 * 通过后挂 $request->oauth_user_id / oauth_client / oauth_scopes。
 */
class OAuthTokenAuth implements MiddlewareInterface
{
  public function process(Request $request, callable $next): Response
  {
    $token = $request->header('authorization', '');
    if (!preg_match('/^bearer\s+/i', (string) $token)) {
      return fail("缺少 Bearer 访问令牌", 401);
    }

    try {
      $verify = \Tinywan\Jwt\JwtToken::verify();
    } catch (\Throwable $e) {
      return fail("访问令牌无效或已过期", 401);
    }
    $extend = $verify['extend'] ?? [];
    $uid = (int) ($extend['id'] ?? 0);
    $sid = (int) ($extend['sid'] ?? 0);
    if ($uid <= 0 || $sid <= 0) {
      return fail("访问令牌无效", 401);
    }

    // 会话必须 active（撤销链: 撤销授权/停用应用都会下线对应会话）
    $session = SessionService::check($sid);
    if ($session === null || (int) $session['user_id'] !== $uid) {
      return fail("访问令牌已失效", 401);
    }

    // 必须是 OAuth 签发的会话
    $clientId = $session['client_id'] ?? null;
    if (empty($clientId)) {
      return fail("访问令牌类型错误", 401);
    }

    $client = OAuthClientModel::where('client_id', $clientId)->where('status', 'active')->first();
    if (!$client) {
      return fail("应用已被停用", 401);
    }

    $grant = OAuthGrantModel::where('user_id', $uid)
      ->where('client_id', $clientId)
      ->where('status', 'active')
      ->first();
    if (!$grant) {
      return fail("授权已被撤销", 401);
    }

    $request->oauth_user_id = $uid;
    $request->oauth_client = $client;
    $request->oauth_scopes = array_filter(explode(' ', (string) ($session['scope'] ?? '')));

    return $next($request);
  }
}
