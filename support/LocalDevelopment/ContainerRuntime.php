<?php

namespace support\LocalDevelopment;

/** 容器仅在进程内转换本机连接目标，环境文件保持原样。 */
class ContainerRuntime
{
  public static function enabled(): bool
  {
    return getenv('EXWEBSITE_DOCKER') === '1';
  }

  public static function connectionHost(string $host): string
  {
    return self::enabled() &&
      in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
      ? 'host.docker.internal'
      : $host;
  }

  public static function normalizeEnvironment(): void
  {
    if (!self::enabled()) {
      return;
    }
    foreach (['POSTGRES_HOST', 'REDIS_HOST'] as $key) {
      $original = getenv($key);
      if ($original === false || $original === '') {
        $original = '127.0.0.1';
      }
      $host = self::connectionHost($original);
      putenv($key . '=' . $host);
      $_ENV[$key] = $host;
      $_SERVER[$key] = $host;
    }
  }

  public static function summarizeProbe(
    string $name,
    string $status,
    array $target,
  ): array {
    $local = in_array($target['host'], ['127.0.0.1', 'localhost', '::1'], true);
    $port =
      $name === 'postgres' ? getenv('POSTGRES_PORT') : getenv('REDIS_PORT');
    $default = $name === 'postgres' ? 5432 : 6379;
    return [
      'status' => $status,
      'local' => $local,
      'composeCompatible' =>
        $local &&
        (int) $target['port'] ===
          (int) ($port === false || $port === '' ? $default : $port),
    ];
  }
}
