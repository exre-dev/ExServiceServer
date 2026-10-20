<?php

// 独立 CLI：不加载 support/bootstrap.php、.env 或任何业务启动配置。
require dirname(__DIR__) . '/vendor/autoload.php';

use Webman\Route;

try {
  $config = require dirname(__DIR__) . '/config/openapi.php';
  // 只装载 app.php，供框架判断 Controller 后缀和插件启用状态。
  // 不装载数据库、Redis、进程、安装或自动加载配置。
  $loadAppConfig = function (string $path, ?string $key = null): void {
    $excluded = [];
    foreach (
      new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
      )
      as $file
    ) {
      if (
        $file->getExtension() === 'php' &&
        $file->getBasename('.php') !== 'app'
      ) {
        $excluded[] = $file->getBasename('.php');
      }
    }
    \Webman\Config::load($path, array_values(array_unique($excluded)), $key);
  };
  $paths = [config_path()];
  $loadAppConfig(config_path());
  // 与框架 support/bootstrap.php 使用相同的应用插件配置路径。
  foreach (\Webman\Util::scanDir(base_path('plugin')) as $plugin) {
    if (is_dir($path = $plugin . '/config')) {
      $loadAppConfig($path, 'plugin.' . basename($plugin));
      $paths[] = $path;
    }
  }
  Route::load($paths);
  if (!Route::isDefaultRouteDisabled()) {
    throw new RuntimeException('默认业务路由必须关闭，才能完整核对显式契约');
  }
  $routes = [];
  foreach (Route::getRoutes() as $route) {
    foreach ($route->getMethods() as $method) {
      // CORS 通配预检不属于业务接口；fallback 不在 getRoutes() 中。
      if (
        $method === 'OPTIONS' &&
        $route->getPath() === '[{path:.+}]' &&
        $route->getCallback() instanceof \Closure
      ) {
        continue;
      }
      $routes[] = strtoupper($method) . ' ' . $route->getPath();
    }
  }
  fwrite(
    STDOUT,
    json_encode(
      [
        'routes' => $routes,
        'publicOperations' => $config['public_operations'],
      ],
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    ),
  );
} catch (Throwable $exception) {
  fwrite(
    STDERR,
    $exception->getMessage() .
      ' (' .
      $exception->getFile() .
      ':' .
      $exception->getLine() .
      ")\n",
  );
  exit(1);
}
