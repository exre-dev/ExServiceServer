<?php

namespace support\LocalDevelopment;

/** 仅覆盖本轮 PHP 监听与用户侧公开地址。 */
class RuntimeConfiguration
{
  private const CONTEXT_KEYS = [
    'EXWEBSITE_DEV_LISTEN',
    'EXWEBSITE_DEV_WEB_URL',
    'EXWEBSITE_DEV_RUN_ID',
  ];
  private const RESERVED_FILE_KEYS = [
    'EXWEBSITE_DEV_LISTEN',
    'EXWEBSITE_DEV_WEB_URL',
    'EXWEBSITE_DEV_RUN_ID',
    'EXWEBSITE_NATIVE_RUN_ID',
    'EXWEBSITE_DOCKER',
  ];
  private const ENVIRONMENT_FILES = ['.env', '.env.local'];

  private $listen;
  private $webUrl;
  private $runId;

  private function __construct($listen, $webUrl, $runId)
  {
    $this->listen = $listen;
    $this->webUrl = $webUrl;
    $this->runId = $runId;
  }

  public static function current()
  {
    $context = [];
    foreach (self::CONTEXT_KEYS as $key) {
      $value = getenv($key);
      if ($value !== false) {
        $context[$key] = $value;
      }
    }
    return self::fromValues($context, [
      'SERVER_HOST' => getenv('SERVER_HOST'),
      'SERVER_PORT' => getenv('SERVER_PORT'),
      'USER_WEB_URL' => getenv('USER_WEB_URL'),
    ]);
  }

  public static function fromValues(array $context, array $ordinary)
  {
    $present = 0;
    foreach (self::CONTEXT_KEYS as $key) {
      if (array_key_exists($key, $context)) {
        $present++;
      }
    }
    if ($present !== 0 && $present !== count(self::CONTEXT_KEYS)) {
      throw new RuntimeFailure('开发上下文必须完整包含监听、Web 地址与运行 ID');
    }

    if ($present !== 0) {
      $listen = (string) $context['EXWEBSITE_DEV_LISTEN'];
      if (!preg_match('~^http://127\.0\.0\.1:([0-9]+)$~D', $listen, $match)) {
        throw new RuntimeFailure('开发监听必须是回环 HTTP 地址');
      }
      self::port($match[1]);
      $webUrl = self::origin((string) $context['EXWEBSITE_DEV_WEB_URL']);
      $runId = (string) $context['EXWEBSITE_DEV_RUN_ID'];
      if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $runId)) {
        throw new RuntimeFailure('开发运行 ID 无效');
      }
      return new self($listen, $webUrl, $runId);
    }

    $host = $ordinary['SERVER_HOST'] ?? false;
    $host = $host === false || $host === '' ? '0.0.0.0' : (string) $host;
    $ipv6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    if (
      !$ipv6 &&
      !filter_var($host, FILTER_VALIDATE_IP) &&
      !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
    ) {
      throw new RuntimeFailure('SERVER_HOST 无效');
    }
    $port = $ordinary['SERVER_PORT'] ?? false;
    $port = self::port(
      $port === false || $port === '' ? '8787' : (string) $port,
    );
    $web = $ordinary['USER_WEB_URL'] ?? false;
    $web = self::origin(
      $web === false || $web === '' ? 'http://127.0.0.1:8080' : (string) $web,
    );
    $listenHost = $ipv6 ? "[$host]" : $host;
    return new self("http://$listenHost:$port", $web, null);
  }

  public static function assertNoReservedFileKeys($path)
  {
    if (!is_file($path)) {
      return;
    }
    $content = file_get_contents($path);
    if ($content === false) {
      throw new RuntimeFailure('无法读取 PHP .env');
    }
    foreach (self::RESERVED_FILE_KEYS as $key) {
      if (preg_match('/^\s*(?:export\s+)?' . $key . '\s*=/m', $content)) {
        throw new RuntimeFailure("PHP .env 不允许声明保留字段 $key");
      }
    }
  }

  public static function loadEnvironment($root)
  {
    self::loadEnvironmentFiles($root, true);
    ContainerRuntime::normalizeEnvironment();
  }

  public static function readEnvironment($root)
  {
    return self::loadEnvironmentFiles($root, false);
  }

  public static function containerConnectionHost($host)
  {
    return ContainerRuntime::connectionHost((string) $host);
  }

  /** 原生入口启动前只读解析普通监听端口，不触碰后台依赖。 */
  public static function nativeConfigurationLine($root)
  {
    self::clearContext();
    self::loadEnvironment($root);
    $port = parse_url(self::current()->listen(), PHP_URL_PORT);
    return 'EXWEBSITE_NATIVE_CONFIGURATION ' .
      json_encode(
        [
          'listen' => 'http://127.0.0.1:' . $port,
        ],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
      );
  }

  public static function environmentFiles($root)
  {
    return array_values(
      array_filter(self::ENVIRONMENT_FILES, function ($file) use ($root) {
        return is_file($root . '/' . $file);
      }),
    );
  }

  /** Docker 专用配置仅交给 Compose，不加载到 PHP 应用环境。 */
  public static function composeEnvironmentFiles($root)
  {
    $files = self::environmentFiles($root);
    if (is_file($root . '/.env.docker')) {
      $files[] = '.env.docker';
    }
    return $files;
  }

  private static function loadEnvironmentFiles($root, $apply)
  {
    $files = self::environmentFiles($root);
    if (!$files) {
      return [];
    }
    foreach ($files as $file) {
      self::assertNoReservedFileKeys($root . '/' . $file);
    }
    try {
      // 禁用短路以依次加载两个文件，本地覆盖也可引用基础文件中的值。
      $dotenv = $apply
        ? \Dotenv\Dotenv::createUnsafeMutable($root, $files, false)
        : \Dotenv\Dotenv::createArrayBacked($root, $files, false);
      return $dotenv->load();
    } catch (\Throwable $error) {
      // dotenv 原异常可能包含配置原文，不能附加到可输出的异常链。
      throw new RuntimeFailure('PHP .env/.env.local 解析失败，请检查格式');
    }
  }

  public static function clearContext()
  {
    foreach (self::CONTEXT_KEYS as $key) {
      putenv($key);
      unset($_ENV[$key], $_SERVER[$key]);
    }
  }

  private static function port($value, $minimum = 1024)
  {
    if (
      !preg_match('/^[0-9]{1,5}$/D', (string) $value) ||
      (int) $value < $minimum ||
      (int) $value > 65535
    ) {
      throw new RuntimeFailure('PHP 端口超出有效范围');
    }
    return (int) $value;
  }

  private static function origin($value)
  {
    if (
      !preg_match('~^https?://[^/?#@]+/?$~iD', $value) ||
      preg_match('/[[:space:][:cntrl:]]/', $value) ||
      strpos($value, '\\') !== false
    ) {
      throw new RuntimeFailure('公开 Web 地址必须是纯 HTTP(S) origin');
    }
    $parts = parse_url($value);
    if (
      !is_array($parts) ||
      empty($parts['host']) ||
      isset($parts['user']) ||
      isset($parts['pass'])
    ) {
      throw new RuntimeFailure('公开 Web 地址无效');
    }
    $host = $parts['host'];
    if (substr($host, 0, 1) === '[' && substr($host, -1) === ']') {
      $validHost = filter_var(
        substr($host, 1, -1),
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV6,
      );
    } else {
      $validHost =
        filter_var($host, FILTER_VALIDATE_IP) ?:
        filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }
    if (!$validHost) {
      throw new RuntimeFailure('公开 Web 地址主机无效');
    }
    if (isset($parts['port'])) {
      self::port((string) $parts['port'], 1);
    }
    return rtrim($value, '/');
  }

  public function listen()
  {
    return $this->listen;
  }

  public function socketListen()
  {
    return ContainerRuntime::enabled() ? 'http://0.0.0.0:8787' : $this->listen;
  }

  public function webUrl()
  {
    return $this->webUrl;
  }

  public function readyLine()
  {
    if ($this->runId === null) {
      return null;
    }
    return 'EXWEBSITE_PHP_READY ' .
      json_encode(
        ['runId' => $this->runId, 'listen' => $this->listen],
        JSON_UNESCAPED_SLASHES,
      );
  }

  public static function nativeReadyLine()
  {
    $runId = self::nativeRunId();
    if ($runId === null) {
      return null;
    }
    $port = getenv('SERVER_PORT');
    $port = self::port($port === false || $port === '' ? '8787' : $port);
    return 'EXWEBSITE_NATIVE_READY ' .
      json_encode(
        [
          'runId' => $runId,
          'listen' => 'http://127.0.0.1:' . $port,
        ],
        JSON_UNESCAPED_SLASHES,
      );
  }

  public static function nativeRunId(): ?string
  {
    if (ContainerRuntime::enabled()) {
      return null;
    }
    $runId = getenv('EXWEBSITE_NATIVE_RUN_ID');
    if ($runId === false) {
      return null;
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $runId)) {
      throw new RuntimeFailure('原生运行 ID 无效');
    }
    return $runId;
  }

  public function bindConflictLine()
  {
    if ($this->runId === null) {
      return null;
    }
    return 'EXWEBSITE_PHP_BIND_CONFLICT ' .
      json_encode(
        ['runId' => $this->runId, 'listen' => $this->listen],
        JSON_UNESCAPED_SLASHES,
      );
  }
}
