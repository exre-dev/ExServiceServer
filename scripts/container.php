<?php

use support\LocalDevelopment\ContainerRuntime;
use support\LocalDevelopment\DevelopmentDependencies;
use support\LocalDevelopment\RuntimeConfiguration;
use support\LocalDevelopment\RuntimeFailure;

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/vendor/autoload.php';

try {
  if (
    !ContainerRuntime::enabled() ||
    $argc !== 2 ||
    !in_array($argv[1], ['probe', 'serve'], true)
  ) {
    throw new RuntimeFailure(
      '用法：EXWEBSITE_DOCKER=1 php scripts/container.php probe|serve',
    );
  }
  if (PHP_VERSION !== '8.4.27') {
    throw new RuntimeFailure('容器 PHP 必须为 8.4.27');
  }
  if ($argv[1] === 'serve') {
    $argv = [$root . '/scripts/runtime.php', 'serve'];
    $argc = 2;
    require $root . '/scripts/runtime.php';
    return;
  }
  $raw = RuntimeConfiguration::readEnvironment($root);
  $originalHosts = [
    'postgres' =>
      $raw['POSTGRES_HOST'] ?? (getenv('POSTGRES_HOST') ?: '127.0.0.1'),
    'redis' => $raw['REDIS_HOST'] ?? (getenv('REDIS_HOST') ?: '127.0.0.1'),
  ];
  RuntimeConfiguration::loadEnvironment($root);
  $targets = DevelopmentDependencies::configuration();
  $result = [];
  $deadline = microtime(true) + 20;
  foreach ($targets as $name => $target) {
    $status = DevelopmentDependencies::probeBounded($name, $target, $deadline);
    $target['host'] = $originalHosts[$name];
    $result[$name] = ContainerRuntime::summarizeProbe($name, $status, $target);
  }
  fwrite(
    STDOUT,
    'EXWEBSITE_DEPENDENCIES ' .
      json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) .
      PHP_EOL,
  );
} catch (Throwable $error) {
  $message =
    $error instanceof RuntimeFailure
      ? $error->getMessage()
      : '容器运行环境或配置出错';
  fwrite(STDERR, $message . PHP_EOL);
  exit(1);
}
