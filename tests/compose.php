<?php

// 使用虚构凭据验证 Compose 插值；--runtime 仅启动并回收独立测试项目的 Redis。
require_once __DIR__ . '/../vendor/autoload.php';

use support\LocalDevelopment\DevelopmentDependencies;
use support\LocalDevelopment\RuntimeConfiguration;

(function () {
  $check = function ($condition, $message) {
    if (!$condition) {
      throw new RuntimeException($message);
    }
  };
  $runtime = in_array('--runtime', $_SERVER['argv'], true);
  $root =
    sys_get_temp_dir() . '/exwebsite-compose-test-' . bin2hex(random_bytes(8));
  $project = 'exwebsite-compose-test-' . bin2hex(random_bytes(8));
  mkdir($root);
  $port = 6388;
  if ($runtime) {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(
      strrchr(stream_socket_get_name($socket, false), ':'),
      1,
    );
    fclose($socket);
  }
  copy(dirname(__DIR__) . '/compose.yaml', $root . '/compose.yaml');
  // 应用文件中残留的旧管理密码不能成为新 Docker 配置的来源。
  file_put_contents(
    $root . '/.env',
    "POSTGRES_ADMIN_PASSWORD=legacy-admin-password\nPOSTGRES_HOST=127.0.0.1\nPOSTGRES_PORT=5544\nPOSTGRES_DB=fixture_app\nPOSTGRES_USER=fixture_user\nPOSTGRES_PASSWORD=app-password\nREDIS_PORT=$port\nREDIS_PASSWORD=\n",
  );
  $overlay = "POSTGRES_PORT=5545\nPOSTGRES_PASSWORD=local-app-password\n";
  $dockerPassword = 'docker fixture $literal "quoted"';
  file_put_contents(
    $root . '/.env.docker',
    "POSTGRES_USER=ignored-user\nPOSTGRES_DB=ignored-db\nDOCKER_POSTGRES_ADMIN_PASSWORD='$dockerPassword'\n",
  );
  $redisPassword = 'redis fixture $literal "quoted"';
  file_put_contents(
    $root . '/.env.local',
    $overlay . "REDIS_PASSWORD='$redisPassword'\n",
  );
  file_put_contents($root . '/process.env', "POSTGRES_DB=fixture_app\n");
  $command = [
    'docker',
    'compose',
    '--project-name',
    $project,
    '--file',
    $root . '/compose.yaml',
  ];
  $execute = function ($arguments) use ($command, $root, $check) {
    $environmentOptions = [];
    foreach (RuntimeConfiguration::composeEnvironmentFiles($root) as $file) {
      $environmentOptions[] = '--env-file';
      $environmentOptions[] = $root . '/' . $file;
    }
    $result = DevelopmentDependencies::runCommand(
      array_merge($command, $environmentOptions, $arguments),
      $root,
      microtime(true) + 50,
      '',
      true,
    );
    $check(
      $result['exitCode'] === 0,
      '独立 Compose 检查失败：' . $arguments[0],
    );
    return $result['stdout'];
  };
  $started = false;
  $previousEnvironment = [];
  foreach (['POSTGRES_PORT', 'REDIS_PORT', 'REDIS_PASSWORD'] as $key) {
    $previousEnvironment[$key] = getenv($key);
    putenv($key);
  }
  try {
    $configuration = json_decode(
      $execute(['config', '--format', 'json']),
      true,
      512,
      JSON_THROW_ON_ERROR,
    );
    $postgres = $configuration['services']['postgres'];
    $redis = $configuration['services']['redis'];
    $php = $configuration['services']['php'];
    $check(
      $php['command'] === ['php', 'scripts/container.php', 'serve'],
      'PHP 服务必须使用容器入口',
    );
    $check(
      $php['environment']['EXWEBSITE_DOCKER'] === '1',
      'PHP 容器必须启用内存地址适配',
    );
    $check(
      (int) $php['ports'][0]['target'] === 8787 &&
        $php['ports'][0]['host_ip'] === '127.0.0.1',
      'PHP 容器仅应在宿主机回环发布内部端口',
    );
    $mounts = array_column($php['volumes'], 'type', 'target');
    $check(
      ($mounts['/app/vendor'] ?? null) === 'volume' &&
        ($mounts['/app/runtime'] ?? null) === 'volume',
      '依赖和进程状态应使用独立卷',
    );
    $check(
      ($mounts['/app/runtime/avatars'] ?? null) === 'bind' &&
        ($mounts['/app/runtime/uploads'] ?? null) === 'bind' &&
        ($mounts['/app/public/uploads'] ?? null) === 'bind',
      '现有头像与公开/私有上传文件必须显式挂载',
    );
    $check(
      $postgres['environment']['POSTGRES_USER'] === 'postgres',
      'Compose 管理账号必须固定为 postgres',
    );
    $check(
      $postgres['environment']['POSTGRES_DB'] === 'postgres',
      'Compose 管理库必须固定为 postgres',
    );
    $check(
      str_replace('$$', '$', $postgres['environment']['POSTGRES_PASSWORD']) ===
        $dockerPassword,
      'Compose 必须使用 Docker 专用密码，不能误用应用密码',
    );
    $check(
      (int) $postgres['ports'][0]['published'] === 5545,
      'PostgreSQL 映射端口必须使用 POSTGRES_PORT',
    );
    $check(
      (int) $redis['ports'][0]['published'] === $port,
      'Redis 映射端口必须使用 REDIS_PORT',
    );
    // Compose 的配置导出会再次转义 $；容器内的原值另由 --runtime 验证。
    $check(
      str_replace('$$', '$', $redis['environment']['REDIS_PASSWORD']) ===
        $redisPassword,
      'Redis 密码应原样传递',
    );
    $check(
      !isset($redis['environment']['POSTGRES_PASSWORD']),
      'Docker 管理密码只能传给 PostgreSQL',
    );
    // 环境文件未定义端口和 Redis 密码时，Compose 必须与依赖探测使用同一份进程配置。
    putenv('POSTGRES_PORT=5541');
    putenv('REDIS_PORT=6388');
    try {
      foreach (['process fixture $literal "quoted"', '0', ''] as $password) {
        putenv('REDIS_PASSWORD=' . $password);
        $targets = DevelopmentDependencies::configuration();
        $check(
          $targets['postgres']['port'] === 5541 &&
            $targets['redis']['port'] === 6388 &&
            $targets['redis']['password'] === $password,
          '依赖探测必须使用进程中的有效端口和密码',
        );
        $result = DevelopmentDependencies::runCommand(
          array_merge($command, [
            '--env-file',
            $root . '/process.env',
            '--env-file',
            $root . '/.env.docker',
            'config',
            '--format',
            'json',
          ]),
          $root,
          microtime(true) + 50,
          '',
          true,
        );
        $check($result['exitCode'] === 0, '进程配置的 Compose 检查失败');
        $effective = json_decode(
          $result['stdout'],
          true,
          512,
          JSON_THROW_ON_ERROR,
        );
        $check(
          (int) $effective['services']['postgres']['ports'][0]['published'] ===
            5541 &&
            (int) $effective['services']['redis']['ports'][0]['published'] ===
              6388,
          'Compose 映射端口必须与依赖探测使用的进程配置一致',
        );
        $check(
          str_replace(
            '$$',
            '$',
            $effective['services']['redis']['environment']['REDIS_PASSWORD'],
          ) === $password,
          'Compose Redis 密码必须与依赖探测一致，包括特殊字符、0 和空值',
        );
        $check(
          str_replace(
            '$$',
            '$',
            $effective['services']['postgres']['environment'][
              'POSTGRES_PASSWORD'
            ],
          ) === $dockerPassword,
          '进程中的应用配置不得替代 Docker 管理密码',
        );
      }
    } finally {
      foreach (array_keys($previousEnvironment) as $key) {
        putenv($key);
      }
    }
    unlink($root . '/.env.docker');
    $withoutDocker = json_decode(
      $execute(['config', '--format', 'json']),
      true,
      512,
      JSON_THROW_ON_ERROR,
    );
    $check(
      $withoutDocker['services']['postgres']['environment'][
        'POSTGRES_PASSWORD'
      ] === '',
      '未配置 Docker 密码时不能回退到应用密码',
    );
    $check(
      $withoutDocker['services']['redis'] === $redis,
      '仅使用 Redis 时不应要求 PostgreSQL 的 Docker 配置',
    );
    if ($runtime) {
      foreach (['', $redisPassword] as $password) {
        file_put_contents(
          $root . '/.env.local',
          $overlay . "REDIS_PASSWORD='$password'\n",
        );
        $started = true;
        $execute(['up', '-d', '--wait', '--wait-timeout', '30', 'redis']);
        $client = new Redis();
        try {
          $client->connect('127.0.0.1', $port, 3);
          if ($password !== '') {
            $rejected = false;
            try {
              $rejected = $client->auth('incorrect-fixture-password') === false;
            } catch (RedisException $error) {
              $rejected = true;
            }
            $check($rejected, '启用认证后必须拒绝错误密码');
            $client->auth($password);
          }
          $check((bool) $client->ping(), '独立 Redis 配置无法连接');
        } finally {
          $client->close();
        }
      }
    }
    echo $runtime
      ? "compose interpolation and isolated Redis tests passed\n"
      : "compose interpolation tests passed\n";
  } finally {
    try {
      if ($started) {
        $check(
          (bool) preg_match(
            '/^exwebsite-compose-test-[a-f0-9]{16}$/D',
            $project,
          ),
          '拒绝回收未知 Compose 项目',
        );
        $execute(['down', '--volumes', '--remove-orphans']);
      }
    } finally {
      foreach ($previousEnvironment as $key => $value) {
        $value === false ? putenv($key) : putenv($key . '=' . $value);
      }
      unlink($root . '/.env');
      unlink($root . '/.env.local');
      @unlink($root . '/.env.docker');
      unlink($root . '/process.env');
      unlink($root . '/compose.yaml');
      rmdir($root);
    }
  }
})();
