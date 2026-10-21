<?php

namespace app\common\middleware;

use app\common\exception\ApiException;
use app\common\model\UserModel;
use app\common\services\SessionService;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

use ReflectionClass;

// 登录检查（会话化: JWT 校验 + Redis 会话必须 active, 支持撤销 token 立即生效）
// 401 = 登录态失效(前端收到即跳登录页); 403 = 身份有效但被拒(如封禁)
class CheckLogin implements MiddlewareInterface
{
  public function process(Request $request, callable $next): Response
  {
    // 闭包路由（如 options 预检/fallback）没有控制器, 不做登录检查
    $controller = $request->controller;
    if ($controller === null || $controller === '') {
      return $next($request);
    }

    $action = $request->action;
    $properties = (new ReflectionClass($controller))->getDefaultProperties();
    $noNeedLogin = $properties['noNeedLogin'] ?? [];

    $token = $request->header("Authorization");

    // 访问的方法需要登录
    if ($noNeedLogin !== '*' && !in_array($action, $noNeedLogin)) {
      if ($token === null || $token === '') {
        throw new ApiException("请提供有效token", 401);
      }

      // JWT 签名与有效期校验（无效/过期/格式异常统一转 401, 避免框架异常变成 500）
      try {
        $verify = \Tinywan\Jwt\JwtToken::verify();
      } catch (\Throwable $e) {
        throw new ApiException("登录状态已失效,请重新登录", 401);
      }
      $extend = $verify['extend'] ?? [];
      $uid = (int) ($extend['id'] ?? 0);
      $sid = (int) ($extend['sid'] ?? 0);
      if ($uid <= 0 || $sid <= 0) {
        throw new ApiException("登录凭证无效,请重新登录", 401);
      }

      // 会话必须 active（撤销 = 删 Redis, 立即生效）
      $session = SessionService::check($sid);
      if ($session === null || (int) $session['user_id'] !== $uid) {
        throw new ApiException("登录状态已失效,请重新登录", 401);
      }

      // OAuth 会话始终绑定应用，不能作为本站登录凭据绕过 scope 或借用平台角色。
      // 使用服务端会话快照中的 client_id，与 OAuthTokenAuth 的凭据用途边界一致。
      if ((string) ($session['client_id'] ?? '') !== '') {
        throw new ApiException("请使用本站登录凭证", 401);
      }

      // 用户实时状态（封禁即时拦截）
      $user = UserModel::find($uid);
      if (!$user) {
        throw new ApiException("用户不存在", 401);
      }
      if ((int) $user->ban !== 0) {
        throw new ApiException("用户被禁用", 403);
      }

      $request->uid = $uid;
      $request->sid = $sid;
      $request->auth_session = $session;
    }

    return $next($request);
  }
}
