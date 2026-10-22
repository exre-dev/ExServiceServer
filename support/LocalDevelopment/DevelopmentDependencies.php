<?php

namespace support\LocalDevelopment;

use PDO;
use Redis;
use Throwable;

/** 开发依赖只读探测及按需 Compose 准备。 */
class DevelopmentDependencies
{
  private static $cancelled = null;

  public static function setCancellationCheck($check)
  {
    self::$cancelled = $check;
  }

  public static function configuration()
  {
    return [
      'postgres' => [
        'host' => getenv('POSTGRES_HOST') ?: '127.0.0.1',
        'port' => self::port(getenv('POSTGRES_PORT') ?: '5432'),
        'database' => getenv('POSTGRES_DB') ?: 'webman',
        'user' => getenv('POSTGRES_USER') ?: 'webman',
        'password' =>
          getenv('POSTGRES_PASSWORD') !== false
            ? getenv('POSTGRES_PASSWORD')
            : 'webman123',
      ],
      'redis' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => self::port(getenv('REDIS_PORT') ?: '6379'),
        'database' => (int) (getenv('REDIS_DATABASE') ?: 0),
        'password' =>
          getenv('REDIS_PASSWORD') !== false ? getenv('REDIS_PASSWORD') : '',
      ],
    ];
  }

  /** 原生和容器启动只检查既有目标，绝不创建后台服务。 */
  public static function checkOnly(
    callable $probe,
    array $targets,
    $deadline,
  ): void {
    foreach ($targets as $name => $target) {
      self::remaining($deadline);
      $status = $probe($name, $target, $deadline);
      if ($status !== 'ready') {
        throw new RuntimeFailure("$name 连接检查失败：$status");
      }
    }
  }

  public static function ensure(
    callable $probe,
    callable $compose,
    array $targets,
    $deadline,
  ) {
    $missing = [];
    foreach ($targets as $name => $target) {
      self::remaining($deadline);
      $result = $probe($name, $target, $deadline);
      if ($result === 'ready') {
        continue;
      }
      if ($result !== 'refused') {
        throw new RuntimeFailure(
          "$name 连接检查失败：{$result}；不会启动 Compose",
        );
      }
      if (!in_array($target['host'], ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeFailure(
          "$name 目标不在本机回环，无法使用本地 Compose",
        );
      }
      $expected =
        $name === 'postgres'
          ? (getenv('POSTGRES_PORT') ?:
          '5432')
          : (getenv('REDIS_PORT') ?:
          '6379');
      if ((int) $target['port'] !== (int) $expected) {
        throw new RuntimeFailure("$name 端口与 Compose 映射不一致");
      }
      $missing[] = $name;
    }
    if (!$missing) {
      return;
    }
    $composeError = null;
    try {
      $compose($missing, $deadline);
    } catch (Throwable $error) {
      $composeError = $error;
    }
    foreach ($targets as $name => $target) {
      self::remaining($deadline);
      $result = $probe($name, $target, $deadline);
      if ($result !== 'ready') {
        if ($composeError) {
          $cause =
            $composeError instanceof RuntimeFailure
              ? $composeError->getMessage()
              : 'Docker CLI 执行错误';
          throw new RuntimeFailure(
            'Compose 未能准备后台服务：' . $cause . '；应用连接仍不可用',
            0,
            $composeError,
          );
        }
        throw new RuntimeFailure("$name 在 Compose 后仍不可用：$result");
      }
    }
  }

  public static function probe($name, array $target, $deadline)
  {
    if ($name === 'postgres' && !extension_loaded('pdo_pgsql')) {
      return 'pdo_pgsql extension missing';
    }
    if ($name === 'redis' && !extension_loaded('redis')) {
      return 'redis extension missing';
    }
    $seconds = min(3, self::remaining($deadline));
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
      'tcp://' . $target['host'] . ':' . $target['port'],
      $errno,
      $errstr,
      $seconds,
    );
    if (!$socket) {
      if (
        in_array($errno, [111, 10061], true) ||
        stripos($errstr, 'refused') !== false ||
        stripos($errstr, '拒绝') !== false
      ) {
        return 'refused';
      }
      return $errno === 110 || $errno === 10060 ? 'timeout' : 'network';
    }
    fclose($socket);
    try {
      if ($name === 'postgres') {
        $timeout = max(1, (int) ceil(min(3, self::remaining($deadline))));
        // SELECT 1 is bounded by the owning helper process; avoid libpq options
        // here because PDO's DSN parser may reject the embedded space in -c.
        $dsn =
          'pgsql:host=' .
          $target['host'] .
          ';port=' .
          $target['port'] .
          ';dbname=' .
          $target['database'] .
          ';connect_timeout=' .
          $timeout;
        $db = new PDO($dsn, $target['user'], $target['password'], [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $db->query('SELECT 1')->fetchColumn();
        $db = null;
        return 'ready';
      }
      $redis = new Redis();
      $redis->connect(
        $target['host'],
        (int) $target['port'],
        min(3, self::remaining($deadline)),
        null,
        0,
        min(3, self::remaining($deadline)),
      );
      if (($target['password'] ?? '') !== '') {
        $redis->setOption(
          Redis::OPT_READ_TIMEOUT,
          min(3, self::remaining($deadline)),
        );
        if ($redis->auth($target['password']) === false) {
          return 'authentication';
        }
      }
      $redis->setOption(
        Redis::OPT_READ_TIMEOUT,
        min(3, self::remaining($deadline)),
      );
      if ($redis->select((int) $target['database']) === false) {
        return 'database missing';
      }
      $redis->setOption(
        Redis::OPT_READ_TIMEOUT,
        min(3, self::remaining($deadline)),
      );
      if ($redis->ping() === false) {
        return 'protocol';
      }
      $redis->close();
      return 'ready';
    } catch (Throwable $error) {
      $message = strtolower($error->getMessage());
      if (
        strpos($message, 'password') !== false ||
        strpos($message, 'auth') !== false ||
        strpos($message, '28p01') !== false
      ) {
        return 'authentication';
      }
      if (
        strpos($message, 'does not exist') !== false ||
        strpos($message, '3d000') !== false
      ) {
        return 'database missing';
      }
      if (
        strpos($message, 'timed out') !== false ||
        strpos($message, 'timeout expired') !== false
      ) {
        return 'timeout';
      }
      return 'protocol';
    }
  }

  public static function probeBounded($name, array $target, $deadline)
  {
    $payload = json_encode([
      'name' => $name,
      'target' => $target,
      'deadline' => $deadline,
    ]);
    if ($payload === false) {
      return 'configuration';
    }
    $root = dirname(__DIR__, 2);
    $result = self::runCommand(
      [PHP_BINARY, $root . '/scripts/dependency-probe.php'],
      $root,
      $deadline,
      $payload,
      true,
    );
    if ($result['exitCode'] !== 0) {
      return 'protocol';
    }
    $status = trim($result['stdout']);
    return in_array(
      $status,
      [
        'ready',
        'refused',
        'timeout',
        'network',
        'authentication',
        'database missing',
        'protocol',
        'pdo_pgsql extension missing',
        'redis extension missing',
      ],
      true,
    )
      ? $status
      : 'protocol';
  }

  public static function compose(array $services, $deadline, $root)
  {
    $command = [
      'docker',
      'compose',
      '--project-name',
      'exre-php-local',
      '--file',
      $root . '/compose.yaml',
    ];
    foreach (RuntimeConfiguration::composeEnvironmentFiles($root) as $file) {
      $command[] = '--env-file';
      $command[] = $root . '/' . $file;
    }
    $command = array_merge($command, [
      'up',
      '-d',
      '--wait',
      '--no-recreate',
      '--wait-timeout',
      (string) max(1, (int) floor(self::remaining($deadline) - 3)),
    ]);
    foreach ($services as $name) {
      $command[] = $name;
    }
    $result = self::runCommand($command, $root, $deadline);
    if ($result['exitCode'] !== 0) {
      throw new RuntimeFailure(
        'Docker Compose 启动失败，请检查 Docker 服务、PHP 环境配置及 .env.docker 初始化管理密码',
      );
    }
  }

  /** 子进程仅经 stdin 收到凭据；stdout 只用于固定状态，stderr 丢弃。 */
  public static function runCommand(
    array $command,
    $root,
    $deadline,
    $input = '',
    $captureOutput = false,
  ) {
    self::remaining($deadline, 3);
    if (strlen($input) > 3000) {
      throw new RuntimeFailure('后台依赖连接配置过长');
    }
    $outputPath = $captureOutput
      ? tempnam(sys_get_temp_dir(), 'exwebsite-probe-')
      : null;
    if ($captureOutput && $outputPath === false) {
      throw new RuntimeFailure('无法创建临时依赖探测输出');
    }
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $isDocker = $command[0] === 'docker';
    if (DIRECTORY_SEPARATOR === '/') {
      $command = [
        PHP_BINARY,
        dirname(__DIR__, 2) . '/scripts/process-group.php',
        ...$command,
      ];
    }
    try {
      $process = @proc_open(
        $command,
        [
          ['pipe', 'r'],
          ['file', $outputPath ?: $null, 'w'],
          ['file', $null, 'w'],
        ],
        $pipes,
        $root,
        self::childEnvironment($isDocker),
        ['bypass_shell' => true],
      );
    } catch (Throwable $error) {
      if ($outputPath) {
        @unlink($outputPath);
      }
      throw new RuntimeFailure('无法启动后台依赖检查命令', 0, $error);
    }
    if (!is_resource($process)) {
      if ($outputPath) {
        @unlink($outputPath);
      }
      throw new RuntimeFailure('无法启动后台依赖检查命令');
    }
    // Windows 的 proc_open stdout pipe 即使设为非阻塞，fread 仍可能等待子进程退出。
    // 输出只写入私有临时文件（或空设备），父进程轮询句柄并在退出后读取固定探测状态。
    if ($input !== '') {
      $written = @fwrite($pipes[0], $input);
      if ($written !== strlen($input)) {
        fclose($pipes[0]);
        self::stopProcess($process, $deadline);
        if (!proc_get_status($process)['running']) {
          proc_close($process);
        }
        if ($outputPath) {
          @unlink($outputPath);
        }
        throw new RuntimeFailure('无法传递后台依赖探测配置');
      }
    }
    fclose($pipes[0]);
    $status = null;
    try {
      while (true) {
        if ($outputPath && is_file($outputPath)) {
          clearstatcache(true, $outputPath);
          if (filesize($outputPath) > 8192) {
            throw new RuntimeFailure('后台依赖探测输出过长');
          }
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
          break;
        }
        self::remaining($deadline, 3);
        usleep(50000);
      }
    } catch (Throwable $error) {
      try {
        self::stopProcess($process, $deadline);
      } catch (Throwable $cleanupError) {
        $error = $cleanupError;
      }
      if (!proc_get_status($process)['running']) {
        proc_close($process);
      }
      if ($outputPath) {
        @unlink($outputPath);
      }
      throw $error;
    }
    proc_close($process);
    $stdout = $outputPath ? (string) @file_get_contents($outputPath) : '';
    if ($outputPath) {
      @unlink($outputPath);
    }
    return ['exitCode' => $status['exitcode'], 'stdout' => $stdout];
  }

  private static function stopProcess($process, $deadline)
  {
    $status = proc_get_status($process);
    if (!$status['running']) {
      return;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
      $treeKilled = false;
      // taskkill can itself produce enough output to fill a pipe on a large tree.
      $killer = @proc_open(
        ['taskkill', '/F', '/T', '/PID', (string) $status['pid']],
        [['file', 'NUL', 'r'], ['file', 'NUL', 'w'], ['file', 'NUL', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
      );
      if (is_resource($killer)) {
        $killDeadline = min($deadline - 0.3, microtime(true) + 2.5);
        do {
          $killerStatus = proc_get_status($killer);
          if (!$killerStatus['running']) {
            break;
          }
          usleep(50000);
        } while (microtime(true) < $killDeadline);
        if ($killerStatus['running']) {
          @proc_terminate($killer);
        }
        $treeKilled =
          !$killerStatus['running'] && $killerStatus['exitcode'] === 0;
        if (!proc_get_status($killer)['running']) {
          proc_close($killer);
        }
      }
      if (proc_get_status($process)['running']) {
        @proc_terminate($process);
      }
    } else {
      $ownedGroup =
        function_exists('posix_getpgid') &&
        @posix_getpgid($status['pid']) === $status['pid'];
      if ($ownedGroup) {
        @posix_kill(-$status['pid'], 15);
      } else {
        @proc_terminate($process, 15);
      }
      $grace = min($deadline - 0.5, microtime(true) + 0.5);
      while (proc_get_status($process)['running'] && microtime(true) < $grace) {
        usleep(50000);
      }
      if ($ownedGroup) {
        @posix_kill(-$status['pid'], 9);
      }
      if (proc_get_status($process)['running']) {
        @proc_terminate($process, 9);
      }
    }
    while (
      proc_get_status($process)['running'] &&
      microtime(true) < $deadline - 0.1
    ) {
      usleep(50000);
    }
    if (proc_get_status($process)['running']) {
      throw new RuntimeFailure('后台依赖子进程取消后未在截止时间内退出');
    }
    if (DIRECTORY_SEPARATOR === '\\' && !$treeKilled) {
      throw new RuntimeFailure('后台依赖进程树未能在截止时间内确认回收');
    }
  }

  private static function remaining($deadline, $reserve = 0)
  {
    if (self::$cancelled && call_user_func(self::$cancelled)) {
      throw new RuntimeFailure('PHP 启动已取消', 130);
    }
    $left = $deadline - microtime(true) - $reserve;
    if ($left <= 0) {
      throw new RuntimeFailure('后台依赖准备超过 120 秒截止时间');
    }
    return $left;
  }

  private static function port($value)
  {
    if (
      !preg_match('/^[0-9]{1,5}$/D', (string) $value) ||
      (int) $value < 1 ||
      (int) $value > 65535
    ) {
      throw new RuntimeFailure('后台服务端口无效');
    }
    return (int) $value;
  }

  /** 探测凭据通过 stdin 提供；Docker 另接收与探测一致的应用端口和 Redis 密码。 */
  private static function childEnvironment($isDocker)
  {
    $names = [
      'PATH',
      'Path',
      'PATHEXT',
      'SystemRoot',
      'WINDIR',
      'ComSpec',
      'TEMP',
      'TMP',
      'TMPDIR',
      'HOME',
      'USERPROFILE',
      'APPDATA',
      'LOCALAPPDATA',
      'XDG_CONFIG_HOME',
      'PHPRC',
      'PHP_INI_SCAN_DIR',
      'LD_LIBRARY_PATH',
      'LANG',
      'LC_ALL',
      'TZ',
    ];
    if ($isDocker) {
      $names = array_merge($names, [
        'POSTGRES_PORT',
        'REDIS_PORT',
        'REDIS_PASSWORD',
        'DOCKER_HOST',
        'DOCKER_CONTEXT',
        'DOCKER_CONFIG',
        'DOCKER_CERT_PATH',
        'DOCKER_TLS_VERIFY',
        'DOCKER_API_VERSION',
        'DOCKER_DEFAULT_PLATFORM',
        'HTTP_PROXY',
        'HTTPS_PROXY',
        'NO_PROXY',
        'http_proxy',
        'https_proxy',
        'no_proxy',
        'SSL_CERT_FILE',
        'CURL_CA_BUNDLE',
      ]);
    }
    $environment = [];
    foreach ($names as $name) {
      $value = getenv($name);
      if ($value !== false) {
        $environment[$name] = $value;
      }
    }
    return $environment;
  }
}
