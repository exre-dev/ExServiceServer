<?php

require_once __DIR__ . '/../vendor/autoload.php';

use support\LocalDevelopment\ContainerRuntime;
use support\LocalDevelopment\DevelopmentDependencies;
use support\LocalDevelopment\RuntimeConfiguration;
use support\LocalDevelopment\RuntimeFailure;

$check = function ($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$saved = [];
foreach (
  [
    'EXWEBSITE_DOCKER',
    'EXWEBSITE_DEV_LISTEN',
    'EXWEBSITE_DEV_WEB_URL',
    'EXWEBSITE_DEV_RUN_ID',
    'POSTGRES_HOST',
    'REDIS_HOST',
  ]
  as $key
) {
  $saved[$key] = getenv($key);
}
try {
  putenv('EXWEBSITE_DOCKER=1');
  putenv('EXWEBSITE_DEV_LISTEN=http://127.0.0.1:8081');
  putenv('EXWEBSITE_DEV_WEB_URL=http://127.0.0.1:8080');
  putenv('EXWEBSITE_DEV_RUN_ID=container-test');
  $configuration = RuntimeConfiguration::current();
  $check(
    $configuration->listen() === 'http://127.0.0.1:8081',
    '公开监听地址必须保持主机端口',
  );
  $check(
    $configuration->socketListen() === 'http://0.0.0.0:8787',
    '容器监听地址必须为内部端口',
  );
  $check(
    str_contains(
      $configuration->readyLine(),
      '"listen":"http://127.0.0.1:8081"',
    ),
    'READY 必须报告公开地址',
  );
  $check(
    ContainerRuntime::connectionHost('127.0.0.1') === 'host.docker.internal',
    '本机连接应适配到 Docker 宿主机',
  );
  $check(
    ContainerRuntime::connectionHost('localhost') === 'host.docker.internal',
    'localhost 应适配',
  );
  $check(
    ContainerRuntime::connectionHost('remote.example') === 'remote.example',
    '远程地址不应适配',
  );
  putenv('POSTGRES_HOST=127.0.0.1');
  putenv('REDIS_HOST=localhost');
  ContainerRuntime::normalizeEnvironment();
  $check(
    getenv('POSTGRES_HOST') === 'host.docker.internal' &&
      getenv('REDIS_HOST') === 'host.docker.internal',
    '进程配置应适配且不写文件',
  );
  $summary = ContainerRuntime::summarizeProbe('postgres', 'refused', [
    'host' => '127.0.0.1',
    'port' => 5432,
    'password' => 'SECRET',
  ]);
  $check(
    $summary === [
      'status' => 'refused',
      'local' => true,
      'composeCompatible' => true,
    ],
    '探测摘要分类错误',
  );
  $check(!str_contains(json_encode($summary), 'SECRET'), '探测摘要泄露凭据');
  $check(
    ContainerRuntime::summarizeProbe('redis', 'authentication', [
      'host' => 'localhost',
      'port' => 6379,
    ])['composeCompatible'] === true,
    '认证失败仍应保留拓扑分类',
  );
  $check(
    ContainerRuntime::summarizeProbe('redis', 'refused', [
      'host' => 'remote.example',
      'port' => 6379,
    ])['local'] === false,
    '远程目标不应标记本地',
  );
  $seen = [];
  $targets = [
    'postgres' => ['host' => 'fixture'],
    'redis' => ['host' => 'fixture'],
  ];
  DevelopmentDependencies::checkOnly(
    function ($name) use (&$seen) {
      $seen[] = $name;
      return 'ready';
    },
    $targets,
    microtime(true) + 1,
  );
  $check($seen === ['postgres', 'redis'], '本机启动应检查全部依赖');
  try {
    DevelopmentDependencies::checkOnly(
      function () {
        return 'authentication';
      },
      $targets,
      microtime(true) + 1,
    );
    throw new RuntimeException('认证失败不应启动 PHP');
  } catch (RuntimeFailure $error) {
    $check(
      str_contains($error->getMessage(), 'authentication'),
      '本机启动应报告既有固定分类',
    );
  }
  echo "container runtime tests passed\n";
} finally {
  foreach ($saved as $key => $value) {
    $value === false ? putenv($key) : putenv($key . '=' . $value);
  }
}
