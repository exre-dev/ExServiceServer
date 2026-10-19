<?php

namespace app\common\middleware;

use app\common\services\InstallationService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/** 未完成初始化时，在任何登录、业务查询及 Redis 限流前阻止业务请求。 */
class RequireInstallation implements MiddlewareInterface
{
  public function process(Request $request, callable $next): Response
  {
    if ($request->controller === \app\system\InstallationController::class) {
      return $next($request);
    }
    if (!InstallationService::isInstalled()) {
      return fail('系统尚未初始化，请先打开管理端完成初始化', 503)->withHeader('X-Setup-Required', '1');
    }
    InstallationService::refreshConfiguration(true);
    return $next($request);
  }
}
