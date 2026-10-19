<?php

namespace app\common\middleware;

use support\Redis;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 记录访问者信息及内容 记录执行时间
 */
class Timing implements MiddlewareInterface
{
    /**
     * @return Response $response
     */
    public function process(Request $request, callable $next): Response
    {
        // 初始化表单含数据库、Redis 和管理员密码，不进入请求参数日志。
        if ($request->controller === \app\system\InstallationController::class) {
            return $next($request);
        }
        // 开始时间
        $start_time = microtime(true);

        // 当前请求的应用
        $app = $request->app;
        // IP地址
        $ip = $request->getRealIp();
        // 请求路径
        $path = $request->uri();
        // 请求方法
        $method = $request->method();
        // GET请求参数
        $get = $request->get();
        // POST请求参数
        $post = $request->post();

        // 调用下一个中间件或者控制器 
        /**  @var Response $response */
        $response = $next($request);
        // 结束时间
        $end_time = microtime(true);
        // 执行时间
        $execution_time = round(($end_time - $start_time) * 1000, 2);
        // 记录执行时间
        $response->header('X-Run-Tick', $execution_time); // 添加执行时间到响应头
        // $response->header('Server', "nginx"); // 伪装服务器信息

        // $r_log = [
        //     "app" => $request->module,
        //     "ip" => $ip,
        //     "path" => $path,
        //     "method" => $method,
        //     "get" => $get,
        //     "post" => $post,
        //     "exception" => false,
        //     "execution_time" => $execution_time,
        //     "start_time" => $start_time,
        // ];

        // 获取错误日志
        $exception = $response->exception();
        if ($exception != null || $app === "miss") {
            // $r_log["exception_message"] = $exception->getMessage();
            // $r_log["exception_file"] = $exception->getFile() . ':' . $exception->getLine();

            // 记录日志
            echo "\n";
            echo "app : $app \n";
            echo "IP地址: $ip\n";
            echo "请求路径：$path\n";
            echo "请求方法：$method\n";
            echo "请求参数G/P: " . json_encode($get) . "/" . json_encode($post) . "\n";
            echo "执行耗时：{$execution_time}ms\n";
            echo "错误消息：\n";
            print_r($exception?->getMessage());
            echo "\n错误文件: \n";
            print_r($exception ? $exception->getFile() . ':' . $exception->getLine() : null);
            print_r("\n");
            echo "------------------------------------\n";
        }

        return $response;
    }
}
