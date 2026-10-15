<?php

namespace app\common\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use support\Redis;

/**
 * 检查当前请求是否超过限制
 */
class Limit implements MiddlewareInterface
{
    protected $app;

    public function __construct(string $app = 'app')
    {
        $this->app = $app;
    }

    /**
     * @param Request $request
     * @return Response $response
     */
    public function process(Request $request, callable $next): Response
    {
        // IP地址
        $ip = $request->getRealIp();

        // debug 开启时本机请求不限流（本地联调会产生大量预期的失败请求）
        if (config('app.debug') && in_array($ip, ['127.0.0.1', '::1'], true)) {
            $request->module = $this->app;
            return $next($request);
        }

        $num = (int) Redis::get("rip_$ip") ?? 0;
        if ($num >= 5) {
            return fail("请求过于频繁，请稍后再试！", 403);
        }

        $request->module = $this->app;

        // 调用下一个中间件或者控制器 
        //@var Response $response
        $response = $next($request);

        // 获取错误日志
        $exception = $response->exception();

        if ($request->module === "miss" || $exception != null || $response->getStatusCode() !== 200) {
            Redis::set("rip_$ip", $num + 1, "EX", 30);
        }

        return $response;
    }
}
