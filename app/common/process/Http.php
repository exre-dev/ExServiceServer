<?php

namespace app\common\process;

use Webman\App;
use Webman\Http\Request;
use Webman\Http\Response;

class Http extends App
{
  public function onWorkerStart($worker)
  {
    self::afterWorkerStarted(function () use ($worker) {
      parent::onWorkerStart($worker);
    });
  }

  /** 仅在 Webman 完成 worker 初始化后输出可用于本轮归属判断的标记。 */
  public static function afterWorkerStarted(
    callable $initialize,
    ?callable $emit = null,
  ): void {
    $initialize();
    $emit =
      $emit ??
      function ($line) {
        fwrite(STDOUT, $line . PHP_EOL);
        fflush(STDOUT);
      };
    $line = \support\LocalDevelopment\RuntimeConfiguration::current()->readyLine();
    if ($line !== null) {
      $emit($line);
    }
    $native = \support\LocalDevelopment\RuntimeConfiguration::nativeReadyLine();
    if ($native !== null) {
      $emit($native);
    }
  }

  /** 原生开发入口仅给安装状态响应标记实际应答进程的归属。 */
  protected static function send($connection, $response, $request)
  {
    if (
      $response instanceof Response &&
      $request instanceof Request &&
      $request->path() === '/api/installation/status'
    ) {
      $runId = \support\LocalDevelopment\RuntimeConfiguration::nativeRunId();
      if ($runId !== null) {
        $response->withHeader('X-Exwebsite-Native-Run', $runId);
      }
    }
    parent::send($connection, $response, $request);
  }

  /** 在路由解析和模型依赖注入前应用安装配置。 */
  public function onMessage($connection, $request)
  {
    \app\common\services\InstallationService::refreshConfiguration();
    parent::onMessage($connection, $request);
  }
}
