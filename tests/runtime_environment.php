<?php

// 仅使用临时目录和虚构配置，不读取项目环境文件或连接数据库。
require_once __DIR__ . '/../vendor/autoload.php';

use app\common\services\InstallationService;
use support\LocalDevelopment\RuntimeConfiguration;
use support\LocalDevelopment\DevelopmentDependencies;
use support\LocalDevelopment\RuntimeFailure;

class EnvironmentInstallation extends InstallationService
{
  public static string $directory;

  protected static function root(): string
  {
    return self::$directory;
  }

  public static function values(): array
  {
    return parent::environment();
  }

  public static function configurationPath(): string
  {
    return parent::environmentPath();
  }
}

(function () {
  $check = function ($condition, $message) {
    if (!$condition) {
      throw new RuntimeException($message);
    }
  };
  $root =
    sys_get_temp_dir() .
    '/exwebsite-environment-test-' .
    bin2hex(random_bytes(8));
  mkdir($root);
  EnvironmentInstallation::$directory = $root;
  $keys = [
    'RUNTIME_ENV_BASE',
    'RUNTIME_ENV_VALUE',
    'RUNTIME_ENV_REFERENCE',
    'RUNTIME_ENV_EMPTY',
    'POSTGRES_HOST',
    'POSTGRES_PORT',
    'POSTGRES_DB',
    'POSTGRES_USER',
    'POSTGRES_PASSWORD',
    'DOCKER_POSTGRES_ADMIN_PASSWORD',
  ];
  $previous = [];
  foreach ($keys as $key) {
    $previous[$key] = [
      getenv($key),
      $_ENV[$key] ?? null,
      $_SERVER[$key] ?? null,
    ];
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
  }
  try {
    RuntimeConfiguration::loadEnvironment($root);
    $check(getenv('RUNTIME_ENV_VALUE') === false, '没有环境文件时应保留缺省值');

    file_put_contents($root . '/.env.local', "RUNTIME_ENV_VALUE=local-only\n");
    RuntimeConfiguration::loadEnvironment($root);
    $check(
      getenv('RUNTIME_ENV_VALUE') === 'local-only',
      '仅有 .env.local 时也必须加载',
    );

    file_put_contents(
      $root . '/.env',
      "RUNTIME_ENV_BASE=base\nRUNTIME_ENV_VALUE=base\nRUNTIME_ENV_EMPTY=base\nPOSTGRES_HOST=base-host\nPOSTGRES_PORT=5544\nPOSTGRES_DB=runtime-fixture\nPOSTGRES_USER=runtime-user\nPOSTGRES_PASSWORD=base-password\n",
    );
    file_put_contents(
      $root . '/.env.local',
      "RUNTIME_ENV_VALUE=local\nRUNTIME_ENV_REFERENCE=\${RUNTIME_ENV_BASE}-local\nRUNTIME_ENV_EMPTY=\nPOSTGRES_HOST=local-host\nPOSTGRES_PASSWORD=local-password\n",
    );
    file_put_contents(
      $root . '/.env.docker',
      "RUNTIME_ENV_VALUE=docker-only\nDOCKER_POSTGRES_ADMIN_PASSWORD=docker-only-password\nPOSTGRES_PASSWORD=docker-app-override\n",
    );
    RuntimeConfiguration::loadEnvironment($root);
    $check(getenv('RUNTIME_ENV_BASE') === 'base', '.env 独有值应保留');
    $check(getenv('RUNTIME_ENV_VALUE') === 'local', '.env.local 必须覆盖 .env');
    $check(
      getenv('RUNTIME_ENV_REFERENCE') === 'base-local',
      '本地覆盖应能引用基础配置',
    );
    $check(getenv('RUNTIME_ENV_EMPTY') === '', '本地空值应覆盖基础配置');
    $check(
      $_ENV['RUNTIME_ENV_VALUE'] === 'local' &&
        $_SERVER['RUNTIME_ENV_VALUE'] === 'local',
      'PHP 环境存储应保持一致',
    );
    $check(
      DevelopmentDependencies::configuration()['postgres'] === [
        'host' => 'local-host',
        'port' => 5544,
        'database' => 'runtime-fixture',
        'user' => 'runtime-user',
        'password' => 'local-password',
      ],
      '依赖探测必须使用 POSTGRES_* 应用配置',
    );
    $database = require __DIR__ . '/../config/database.php';
    $database = $database['connections']['pgsql'];
    $check(
      $database['host'] === 'local-host' &&
        $database['port'] === '5544' &&
        $database['database'] === 'runtime-fixture' &&
        $database['username'] === 'runtime-user' &&
        $database['password'] === 'local-password',
      'ORM 必须使用相同的 POSTGRES_* 应用配置',
    );
    $check(
      EnvironmentInstallation::values()['POSTGRES_HOST'] === 'local-host',
      '安装检查应使用本地覆盖配置',
    );
    $check(
      EnvironmentInstallation::configurationPath() === $root . '/.env.local',
      '安装写入必须选择生效的本地配置文件',
    );
    $check(
      RuntimeConfiguration::environmentFiles($root) === ['.env', '.env.local'],
      '应用环境文件不应包含 Docker 专用配置',
    );
    $check(
      RuntimeConfiguration::composeEnvironmentFiles($root) === [
        '.env',
        '.env.local',
        '.env.docker',
      ],
      'Compose 应按顺序追加独立的 Docker 配置',
    );
    $check(
      getenv('DOCKER_POSTGRES_ADMIN_PASSWORD') === false,
      'Docker 管理密码不应加载到 PHP 运行环境',
    );
    $check(
      RuntimeConfiguration::readEnvironment($root)['POSTGRES_PASSWORD'] ===
        'local-password',
      '安装读取不能混入 Docker 管理密码',
    );

    file_put_contents(
      $root . '/.env.local',
      "RUNTIME_ENV_VALUE=updated-local\n",
    );
    RuntimeConfiguration::loadEnvironment($root);
    $check(
      getenv('RUNTIME_ENV_VALUE') === 'updated-local',
      '重新加载必须读取最新本地覆盖',
    );
    unlink($root . '/.env.local');
    RuntimeConfiguration::loadEnvironment($root);
    $check(getenv('RUNTIME_ENV_VALUE') === 'base', '仅有 .env 时应正常加载');
    $check(
      EnvironmentInstallation::configurationPath() === $root . '/.env',
      '无本地覆盖时安装应写入 .env',
    );

    foreach (['.env', '.env.local'] as $file) {
      file_put_contents(
        $root . '/' . $file,
        "export EXWEBSITE_DEV_RUN_ID=not-allowed\n",
      );
      try {
        RuntimeConfiguration::loadEnvironment($root);
        throw new RuntimeException($file . ' 应拒绝内部开发上下文');
      } catch (RuntimeFailure $error) {
        $check(
          strpos($error->getMessage(), 'EXWEBSITE_DEV_RUN_ID') !== false,
          '应指出违规字段',
        );
      }
      $secret = 'ENVIRONMENT_SECRET_' . bin2hex(random_bytes(8));
      file_put_contents($root . '/' . $file, "POSTGRES_PASSWORD='$secret\n");
      try {
        RuntimeConfiguration::loadEnvironment($root);
        throw new RuntimeException($file . ' 格式错误应失败');
      } catch (RuntimeFailure $error) {
        $check(
          strpos((string) $error, $secret) === false,
          '异常链不得泄露配置中的密码',
        );
      }
      unlink($root . '/' . $file);
    }
    \Dotenv\Dotenv::parse(file_get_contents(__DIR__ . '/../.env.example'));
    echo "environment tests passed\n";
  } finally {
    foreach ($previous as $key => [$value, $envValue, $serverValue]) {
      $value === false ? putenv($key) : putenv($key . '=' . $value);
      if ($envValue === null) {
        unset($_ENV[$key]);
      } else {
        $_ENV[$key] = $envValue;
      }
      if ($serverValue === null) {
        unset($_SERVER[$key]);
      } else {
        $_SERVER[$key] = $serverValue;
      }
    }
    @unlink($root . '/.env');
    @unlink($root . '/.env.local');
    @unlink($root . '/.env.docker');
    rmdir($root);
  }
})();
