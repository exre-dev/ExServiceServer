<?php

if (PHP_VERSION !== '8.4.27') {
  fwrite(STDERR, "PHP 启动失败：本机 PHP 必须为 8.4.27\n");
  exit(1);
}

$root = dirname(__DIR__);
try {
  if (!is_file($root . '/vendor/autoload.php')) {
    throw new RuntimeException('Composer dependencies unavailable');
  }
  require_once $root . '/vendor/autoload.php';
  if ($argc !== 1) {
    throw new \support\LocalDevelopment\RuntimeFailure(
      '用法：php scripts/native-configuration.php',
    );
  }
  $line = \support\LocalDevelopment\RuntimeConfiguration::nativeConfigurationLine(
    $root,
  );
  fwrite(STDOUT, $line . PHP_EOL);
} catch (Throwable $error) {
  $message =
    $error instanceof \support\LocalDevelopment\RuntimeFailure
      ? $error->getMessage()
      : '原生 PHP 配置解析失败，请检查环境文件';
  fwrite(STDERR, 'PHP 启动失败：' . $message . PHP_EOL);
  exit(1);
}
