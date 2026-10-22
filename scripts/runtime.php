<?php

use support\LocalDevelopment\DevelopmentDependencies;
use support\LocalDevelopment\LocalRuntimeApp;
use support\LocalDevelopment\RuntimeConfiguration;
use support\LocalDevelopment\RuntimeFailure;

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/vendor/autoload.php';

try {
  if ($argc !== 2 || !in_array($argv[1], ['start', 'dev', 'serve'], true)) {
    throw new RuntimeFailure('用法：php scripts/runtime.php start|dev|serve');
  }
  $mode = $argv[1];
  if ($mode === 'start') {
    RuntimeConfiguration::clearContext();
  }
  RuntimeConfiguration::loadEnvironment($root);
  RuntimeConfiguration::current();
  if (
    DIRECTORY_SEPARATOR === '/' &&
    (!extension_loaded('pcntl') || !extension_loaded('posix'))
  ) {
    throw new RuntimeFailure('Linux PHP 启动需要 pcntl 和 posix 扩展');
  }

  $lockDir = $root . '/runtime/local-dev';
  if (!is_dir($lockDir) && !mkdir($lockDir, 0777, true) && !is_dir($lockDir)) {
    throw new RuntimeFailure('无法创建 PHP 本地运行锁目录');
  }
  $lock = fopen($lockDir . '/php.lock', 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeFailure(
      '本 checkout 已有 PHP 启动命令运行，请在原终端处理',
    );
  }
  // 锁句柄保持打开，Webman/Windows 包装层返回前均不释放。
  $GLOBALS['exwebsitePhpRuntimeLock'] = $lock;

  $cancelled = false;
  if (
    DIRECTORY_SEPARATOR === '\\' &&
    function_exists('sapi_windows_set_ctrl_handler')
  ) {
    sapi_windows_set_ctrl_handler(function () use (&$cancelled) {
      $cancelled = true;
      $GLOBALS['exwebsiteRuntimeCancelled'] = true;
      return true;
    });
  } elseif (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use (&$cancelled) {
      $cancelled = true;
    });
    pcntl_signal(SIGTERM, function () use (&$cancelled) {
      $cancelled = true;
    });
  }
  DevelopmentDependencies::setCancellationCheck(function () use (&$cancelled) {
    return $cancelled;
  });

  if ($mode === 'serve') {
    DevelopmentDependencies::checkOnly(
      [DevelopmentDependencies::class, 'probeBounded'],
      DevelopmentDependencies::configuration(),
      microtime(true) + 20,
    );
  } elseif ($mode === 'dev') {
    DevelopmentDependencies::ensure(
      [DevelopmentDependencies::class, 'probeBounded'],
      function ($services, $deadline) use ($root) {
        DevelopmentDependencies::compose($services, $deadline, $root);
      },
      DevelopmentDependencies::configuration(),
      microtime(true) + 120,
    );
  }

  if ($cancelled) {
    throw new RuntimeFailure('PHP 启动已取消', 130);
  }

  if (DIRECTORY_SEPARATOR === '\\') {
    $GLOBALS['exwebsitePhpRuntimeChecked'] = true;
    require $root . '/windows.php';
  } else {
    $argv = [$root . '/start.php', 'start'];
    $_SERVER['argv'] = $argv;
    $argc = 2;
    $_SERVER['argc'] = 2;
    LocalRuntimeApp::run();
  }
} catch (Throwable $error) {
  $message =
    $error instanceof RuntimeFailure
      ? $error->getMessage()
      : '运行环境或配置出错，请检查 PHP 日志';
  fwrite(STDERR, 'PHP 启动失败：' . $message . PHP_EOL);
  $code = $error->getCode();
  exit(is_int($code) && $code > 0 && $code < 256 ? $code : 1);
}
