<?php

/**
 * 独立进程内的真实 Webman HTTP 管线；仅传输层和发信使用替身。
 * 不监听 socket、不读取项目 .env；仅接受 WEBSITE_HTTP_TEST_* 显式临时服务。
 */
namespace app\common\services {
  class Email
  {
    public static array $messages = [];

    public static function send(
      string $to,
      string $subject,
      string $body,
      bool $isHtml = true,
    ): bool {
      self::$messages[] = compact('to', 'subject', 'body', 'isHtml');
      return true;
    }
  }
}

namespace {
  use app\common\process\Http;
  use app\common\services\Email;
  use app\common\services\InstallationService;
  use app\common\services\NotificationService;
  use app\common\services\StorageService;
  use support\Db;
  use support\Redis;
  use support\Request;
  use Symfony\Component\Yaml\Yaml;
  use Webman\Http\Response;
  use Workerman\Connection\TcpConnection;
  use Workerman\Protocols\Http as HttpProtocol;

  function verify(bool $condition, string $message): void
  {
    if (!$condition) {
      throw new RuntimeException($message);
    }
  }

  function testEnvironment(): array
  {
    verify(
      getenv('WEBSITE_HTTP_TEST_ENABLED') === '1',
      '需要 WEBSITE_HTTP_TEST_ENABLED=1 和独立临时测试服务',
    );
    $values = [];
    foreach (
      [
        'POSTGRES_HOST',
        'POSTGRES_PORT',
        'POSTGRES_DB',
        'POSTGRES_USER',
        'POSTGRES_PASSWORD',
        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_DATABASE',
        'REDIS_PASSWORD',
      ]
      as $key
    ) {
      $value = getenv('WEBSITE_HTTP_TEST_' . $key);
      verify($value !== false, '缺少显式测试变量 WEBSITE_HTTP_TEST_' . $key);
      // Windows 进程环境不能保留空值，使用显式标记表示无密码。
      $values[$key] =
        str_ends_with($key, '_PASSWORD') && $value === '__EMPTY__'
          ? ''
          : $value;
    }
    foreach (['POSTGRES_HOST', 'REDIS_HOST'] as $key) {
      verify(
        in_array($values[$key], ['127.0.0.1', 'localhost', '::1'], true),
        $key . ' 必须是独立测试服务的回环地址',
      );
    }
    foreach (['POSTGRES_PORT', 'REDIS_PORT'] as $key) {
      verify(
        ctype_digit($values[$key]) &&
          (int) $values[$key] > 0 &&
          (int) $values[$key] <= 65535,
        $key . ' 非法',
      );
    }
    verify(
      (bool) preg_match(
        '/^website_http_test_[a-z0-9_]+$/D',
        $values['POSTGRES_DB'],
      ),
      '测试数据库必须使用 website_http_test_ 前缀',
    );
    verify(
      $values['POSTGRES_USER'] !== '' &&
        !str_contains($values['POSTGRES_USER'], "\0"),
      '测试数据库用户不能为空',
    );
    verify(
      ctype_digit($values['REDIS_DATABASE']) &&
        (int) $values['REDIS_DATABASE'] <= 15,
      '测试 Redis database 非法',
    );
    return $values;
  }

  /** 只复制版本化配置，不遍历项目根目录或运行数据。 */
  function copyConfiguration(string $source, string $destination): void
  {
    verify(!is_link($source), '配置不允许符号链接');
    if (is_dir($source)) {
      verify(mkdir($destination, 0755, true), '创建临时配置目录失败');
      foreach (new DirectoryIterator($source) as $item) {
        if (!$item->isDot()) {
          copyConfiguration(
            $item->getPathname(),
            $destination . '/' . $item->getFilename(),
          );
        }
      }
    } else {
      verify(copy($source, $destination), '复制临时配置失败');
    }
  }

  /** 仅删除已验证随机根目录以内的本次夹具，拒绝链接。 */
  function removeDirectory(string $path, string $root): void
  {
    $normal = str_replace('\\', '/', $path);
    $base = str_replace('\\', '/', $root);
    verify(
      $normal === $base || str_starts_with($normal, $base . '/'),
      '清理路径越界',
    );
    verify(!is_link($path), '清理不得跟随符号链接');
    if (is_dir($path)) {
      foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
          removeDirectory($entry->getPathname(), $root);
        }
      }
      verify(rmdir($path), '临时目录清理失败');
    } elseif (is_file($path)) {
      verify(unlink($path), '临时文件清理失败');
    }
  }

  class ShapeMismatch extends RuntimeException {}

  /**
   * 限定 OpenAPI 3.0 响应子集；format/description 等只作注释，不宣称完整规范验证。
   * 未支持的语义关键字抛 RuntimeException，不能被 oneOf 当成普通分支不匹配吞掉。
   */
  class ResponseContract
  {
    private const SUPPORTED = [
      'type',
      'nullable',
      'required',
      'properties',
      'additionalProperties',
      'items',
      'enum',
      'oneOf',
      'minItems',
      'maxItems',
      'minLength',
      'maxLength',
      'pattern',
      'minimum',
      'maximum',
      'exclusiveMinimum',
      'exclusiveMaximum',
      'description',
      'title',
      'example',
      'default',
      'format',
      'deprecated',
      'readOnly',
      'writeOnly',
    ];

    public array $coverage = [];

    public function __construct(private array $document) {}

    public function resolve(array $node): array
    {
      $seen = [];
      while (isset($node['$ref'])) {
        $ref = $node['$ref'];
        verify(
          is_string($ref) && str_starts_with($ref, '#/components/'),
          '只允许文档内部 components 引用',
        );
        verify(!isset($seen[$ref]), '循环 schema 引用');
        $seen[$ref] = true;
        $node = $this->document;
        foreach (explode('/', substr($ref, 2)) as $part) {
          $part = str_replace(['~1', '~0'], ['/', '~'], $part);
          verify(
            is_array($node) && array_key_exists($part, $node),
            '无法解析 schema 引用 ' . $ref,
          );
          $node = $node[$part];
        }
        verify(is_array($node), 'schema 引用目标非法');
      }
      return $node;
    }

    private function matches(
      bool $condition,
      string $path,
      string $reason,
    ): void {
      if (!$condition) {
        throw new ShapeMismatch($path . ': ' . $reason);
      }
    }

    /** 空数组、缺省可选属性和 nullable 也不能藏住未支持的 schema 语义。 */
    public function supportedSchema(array $schema, int $depth = 0): void
    {
      verify($depth < 80, 'schema 递归超限');
      $schema = $this->resolve($schema);
      foreach (array_keys($schema) as $keyword) {
        verify(
          in_array($keyword, self::SUPPORTED, true),
          '不支持的 schema 关键字 ' . $keyword,
        );
      }
      if (isset($schema['type'])) {
        verify(
          in_array(
            $schema['type'],
            ['object', 'array', 'string', 'integer', 'number', 'boolean'],
            true,
          ),
          '不支持的 schema type',
        );
      }
      foreach ($schema['properties'] ?? [] as $property) {
        $this->supportedSchema($property, $depth + 1);
      }
      foreach ($schema['oneOf'] ?? [] as $branch) {
        $this->supportedSchema($branch, $depth + 1);
      }
      foreach (['items', 'additionalProperties'] as $keyword) {
        if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
          $this->supportedSchema($schema[$keyword], $depth + 1);
        }
      }
    }

    public function shape(
      mixed $value,
      array $schema,
      string $path = '$',
      int $depth = 0,
    ): void {
      verify($depth < 80, 'schema 递归超限');
      $schema = $this->resolve($schema);
      foreach (array_keys($schema) as $keyword) {
        verify(
          in_array($keyword, self::SUPPORTED, true),
          '不支持的 schema 关键字 ' . $keyword . ' at ' . $path,
        );
      }
      if ($value === null && ($schema['nullable'] ?? false)) {
        return;
      }
      if (isset($schema['oneOf'])) {
        $matches = 0;
        foreach ($schema['oneOf'] as $branch) {
          try {
            $this->shape($value, $branch, $path, $depth + 1);
            $matches++;
          } catch (ShapeMismatch) {
            // 仅形状不匹配可继续，未知语义必须失败。
          }
        }
        $this->matches($matches === 1, $path, 'oneOf 必须恰好匹配一个分支');
      }
      if (isset($schema['type'])) {
        $valid = match ($schema['type']) {
          'object' => $value instanceof \stdClass,
          'array' => is_array($value),
          'string' => is_string($value),
          'integer' => is_int($value),
          'number' => is_int($value) || is_float($value),
          'boolean' => is_bool($value),
          default => throw new RuntimeException('不支持的 schema type'),
        };
        $this->matches($valid, $path, '期望 ' . $schema['type']);
      }
      if (array_key_exists('enum', $schema)) {
        $this->matches(
          in_array($value, $schema['enum'], true),
          $path,
          'enum 不匹配',
        );
      }
      if ($value instanceof \stdClass) {
        $properties = $schema['properties'] ?? [];
        foreach ($schema['required'] ?? [] as $key) {
          $this->matches(property_exists($value, $key), $path, '缺少 ' . $key);
        }
        foreach (get_object_vars($value) as $key => $item) {
          if (array_key_exists($key, $properties)) {
            $this->shape(
              $item,
              $properties[$key],
              $path . '.' . $key,
              $depth + 1,
            );
          } else {
            $additional = $schema['additionalProperties'] ?? true;
            $this->matches(
              $additional !== false,
              $path,
              '不允许额外字段 ' . $key,
            );
            if (is_array($additional)) {
              $this->shape($item, $additional, $path . '.' . $key, $depth + 1);
            }
          }
        }
      }
      if (is_array($value)) {
        $this->matches(
          count($value) >= ($schema['minItems'] ?? 0),
          $path,
          'minItems',
        );
        $this->matches(
          count($value) <= ($schema['maxItems'] ?? PHP_INT_MAX),
          $path,
          'maxItems',
        );
        if (isset($schema['items'])) {
          foreach ($value as $index => $item) {
            $this->shape(
              $item,
              $schema['items'],
              $path . '[' . $index . ']',
              $depth + 1,
            );
          }
        }
      }
      if (is_string($value)) {
        $this->matches(
          mb_strlen($value) >= ($schema['minLength'] ?? 0),
          $path,
          'minLength',
        );
        $this->matches(
          mb_strlen($value) <= ($schema['maxLength'] ?? PHP_INT_MAX),
          $path,
          'maxLength',
        );
        if (isset($schema['pattern'])) {
          $this->matches(
            preg_match(
              '~' . str_replace('~', '\~', $schema['pattern']) . '~u',
              $value,
            ) === 1,
            $path,
            'pattern',
          );
        }
      }
      if (is_int($value) || is_float($value)) {
        if (isset($schema['minimum'])) {
          $this->matches(
            $schema['exclusiveMinimum'] ?? false
              ? $value > $schema['minimum']
              : $value >= $schema['minimum'],
            $path,
            'minimum',
          );
        }
        if (isset($schema['maximum'])) {
          $this->matches(
            $schema['exclusiveMaximum'] ?? false
              ? $value < $schema['maximum']
              : $value <= $schema['maximum'],
            $path,
            'maximum',
          );
        }
      }
    }

    public function response(
      string $method,
      string $uri,
      Response $response,
    ): mixed {
      $path = parse_url($uri, PHP_URL_PATH);
      $operation = null;
      $template = null;
      // OpenAPI 具体路径优先于模板，避免 summary/lookup 被 {id} 同时匹配。
      $paths = isset($this->document['paths'][$path])
        ? [$path => $this->document['paths'][$path]]
        : $this->document['paths'];
      foreach ($paths as $candidate => $definition) {
        $parts = preg_split(
          '/(\{[^}]+\})/',
          $candidate,
          -1,
          PREG_SPLIT_DELIM_CAPTURE,
        );
        $pattern = implode(
          '',
          array_map(
            fn($part) => str_starts_with($part, '{')
              ? '[^/]+'
              : preg_quote($part, '~'),
            $parts,
          ),
        );
        if (
          preg_match('~^' . $pattern . '$~D', $path) &&
          isset($definition[strtolower($method)])
        ) {
          verify($operation === null, '请求匹配多个契约 operation');
          $operation = $definition[strtolower($method)];
          $template = $candidate;
        }
      }
      verify($operation !== null, "契约缺少 {$method} {$path}");
      $status = $response->getStatusCode();
      $definition =
        $operation['responses'][$status] ??
        ($operation['responses']['default'] ?? null);
      verify(
        is_array($definition),
        "契约未声明 {$method} {$template} {$status}",
      );
      $definition = $this->resolve($definition);
      $media = strtolower(
        trim(explode(';', (string) $response->getHeader('Content-Type'))[0]),
      );
      verify(
        isset($definition['content'][$media]),
        "契约未声明媒体类型 {$method} {$template} {$status} {$media}",
      );
      $schema = $definition['content'][$media]['schema'] ?? null;
      verify(is_array($schema), '响应缺少 schema');
      $this->supportedSchema($schema);
      $value =
        $media === 'application/json'
          ? json_decode($response->rawBody(), false, 512, JSON_THROW_ON_ERROR)
          : $response->rawBody();
      $this->shape($value, $schema, "{$method} {$template} {$status}");
      $this->coverage["{$method} {$template} {$status}"] = true;
      return $value;
    }

    public function regressionChecks(): void
    {
      $reject = function (callable $action, string $reason): void {
        try {
          $action();
        } catch (ShapeMismatch) {
          return;
        }
        throw new RuntimeException('校验器未拒绝 ' . $reason);
      };
      $actual = ['$ref' => '#/components/schemas/AccountMeResponse'];
      $reject(
        fn() => $this->shape((object) ['msg' => 'ok', 'data' => []], $actual),
        '真实 me 响应的错误 data 类型',
      );
      $reject(
        fn() => $this->shape((object) ['data' => (object) []], $actual),
        '真实响应缺少 required 字段',
      );
      $reject(
        fn() => $this->shape((object) [], ['type' => 'array']),
        'object/array 混淆',
      );
      $reject(
        fn() => $this->shape(
          ['unexpected'],
          ['$ref' => '#/components/schemas/AccountEmptyResponse'],
        ),
        '错误信封',
      );
      $reject(
        fn() => $this->shape([1], ['type' => 'array', 'maxItems' => 0]),
        'maxItems',
      );
      $reject(
        fn() => $this->shape(1, [
          'oneOf' => [['type' => 'integer'], ['type' => 'number']],
        ]),
        'oneOf 多重匹配',
      );
      $reject(
        fn() => $this->shape((object) ['extra' => 1], [
          'type' => 'object',
          'additionalProperties' => false,
        ]),
        '额外属性',
      );
      $reject(
        fn() => $this->shape((object) ['extra' => 'bad'], [
          'type' => 'object',
          'additionalProperties' => ['type' => 'boolean'],
        ]),
        '额外属性 schema',
      );
      try {
        $this->shape(null, ['nullable' => true, 'not' => ['type' => 'string']]);
        throw new RuntimeException('未知语义被忽略');
      } catch (RuntimeException $error) {
        verify(
          str_contains($error->getMessage(), '不支持的 schema 关键字 not'),
          '未知 schema 语义必须明确失败',
        );
      }
      foreach (
        [
          [
            'type' => 'object',
            'properties' => ['absent' => ['not' => ['type' => 'string']]],
          ],
          ['type' => 'array', 'items' => ['not' => ['type' => 'string']]],
        ]
        as $schema
      ) {
        try {
          $this->supportedSchema($schema);
          throw new RuntimeException('未触达字段的未知语义被忽略');
        } catch (RuntimeException $error) {
          verify(
            str_contains($error->getMessage(), '不支持的 schema 关键字 not'),
            '未知嵌套 schema 语义必须明确失败',
          );
        }
      }
      $this->shape(null, ['type' => 'string', 'nullable' => true]);
    }
  }

  $source = dirname(__DIR__);
  $environment = testEnvironment();
  $schema = 'website_http_' . bin2hex(random_bytes(8));
  $prefix = $schema . ':';
  $tempParent = realpath(sys_get_temp_dir());
  verify($tempParent !== false, '系统临时目录不可用');
  $root = $tempParent . DIRECTORY_SEPARATOR . $schema;
  $pdo = null;
  $rawRedis = null;
  $schemaCreated = false;
  $rootCreated = false;
  $failure = null;
  $cleanupErrors = [];
  $oldUploadDirectory = null;
  try {
    verify(!file_exists($root) && mkdir($root, 0755), '无法创建独占测试目录');
    $rootCreated = true;
    define('BASE_PATH', $root);
    foreach ($environment as $key => $value) {
      putenv($key . '=' . $value);
    }
    putenv('JWT_ACCESS_SECRET=' . bin2hex(random_bytes(32)));
    putenv('JWT_REFRESH_SECRET=' . bin2hex(random_bytes(32)));
    putenv('USER_WEB_URL');
    $userWeb = require $source . '/config/userweb.php';
    verify(
      $userWeb['base_url'] === 'http://127.0.0.1:8080',
      '默认 Website 公开地址应为 8080',
    );
    putenv('USER_WEB_URL=http://127.0.0.1:8080');
    putenv('BOT_ENABLE=false');
    copyConfiguration($source . '/config', $root . '/config');
    foreach (
      [
        'app',
        'support',
        'public',
        'runtime/logs',
        'runtime/views',
        'runtime/multipart',
      ]
      as $directory
    ) {
      verify(mkdir($root . '/' . $directory, 0755, true), '临时目录创建失败');
    }
    foreach (
      [
        'app/route.php',
        'app/functions.php',
        'support/Request.php',
        'support/Response.php',
      ]
      as $file
    ) {
      verify(
        copy($source . '/' . $file, $root . '/' . $file),
        '启动文件复制失败',
      );
    }
    file_put_contents($root . '/install.lock', '{"testOnly":true}');
    file_put_contents(
      $root . '/config/database.php',
      "<?php\n\$config = require " .
        var_export($source . '/config/database.php', true) .
        ";\n\$config['connections']['pgsql']['schema'] = " .
        var_export($schema, true) .
        ";\nreturn \$config;\n",
    );
    file_put_contents(
      $root . '/config/redis.php',
      "<?php\n\$config = require " .
        var_export($source . '/config/redis.php', true) .
        ";\n\$config['default']['prefix'] = " .
        var_export($prefix, true) .
        ";\nreturn \$config;\n",
    );
    require $source . '/vendor/autoload.php';
    require $source . '/support/bootstrap.php';
    InstallationService::refreshConfiguration(true);
    verify(
      base_path(false) === $root &&
        runtime_path() === $root . DIRECTORY_SEPARATOR . 'runtime',
      'BASE_PATH/runtime 未隔离',
    );
    verify(
      config('database.connections.pgsql.schema') === $schema &&
        config('redis.default.prefix') === $prefix,
      '首次 refresh 丢失隔离配置',
    );
    verify(
      config('userweb.base_url') === 'http://127.0.0.1:8080',
      '测试公开 Website URL 不正确',
    );

    $pdo = new PDO(
      "pgsql:host={$environment['POSTGRES_HOST']};port={$environment['POSTGRES_PORT']};dbname={$environment['POSTGRES_DB']};connect_timeout=3",
      $environment['POSTGRES_USER'],
      $environment['POSTGRES_PASSWORD'],
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    verify(
      $pdo->query('SELECT current_database()')->fetchColumn() ===
        $environment['POSTGRES_DB'],
      '连接到了非预期测试数据库',
    );
    $pdo->exec('CREATE SCHEMA "' . $schema . '"');
    $schemaCreated = true;
    $pdo->exec('SET search_path TO "' . $schema . '"');
    $ddl = file_get_contents($source . '/database/schema.sql');
    foreach (
      [
        'app_users',
        'app_server_accounts',
        'app_oauth_clients',
        'app_oauth_grants',
        'app_auth_sessions',
        'app_audit_logs',
        'app_notifications',
        'app_notification_prefs',
        'app_feedback_submissions',
        'app_feedback_attachments',
      ]
      as $table
    ) {
      verify(
        preg_match(
          '/CREATE TABLE ' . $table . ' \(.*?\n\);/s',
          $ddl,
          $match,
        ) === 1,
        '找不到需要的建表定义 ' . $table,
      );
      $pdo->exec($match[0]);
      preg_match_all(
        '/CREATE (?:UNIQUE )?INDEX [^\r\n]+ ON ' . $table . ' \([^\r\n]+;/m',
        $ddl,
        $indexes,
      );
      foreach ($indexes[0] as $index) {
        $pdo->exec($index);
      }
    }
    $rawRedis = new \Redis();
    verify(
      $rawRedis->connect(
        $environment['REDIS_HOST'],
        (int) $environment['REDIS_PORT'],
        3,
      ),
      '测试 Redis 连接失败',
    );
    if ($environment['REDIS_PASSWORD'] !== '') {
      verify(
        $rawRedis->auth($environment['REDIS_PASSWORD']),
        '测试 Redis 认证失败',
      );
    }
    verify(
      $rawRedis->select((int) $environment['REDIS_DATABASE']),
      '测试 Redis database 选择失败',
    );
    verify(
      $rawRedis->keys($prefix . '*') === [],
      '测试 Redis namespace 已存在',
    );
    verify(
      Db::connection()
        ->getPdo()
        ->query('SELECT current_schema()')
        ->fetchColumn() === $schema,
      'ORM schema 未隔离',
    );
    verify(
      Redis::connection()->client()->getOption(\Redis::OPT_PREFIX) === $prefix,
      'Redis pool prefix 未隔离',
    );
    // 新建独立连接也必须继承完整配置，不能只给一个已打开的客户端 SET/OPT_PREFIX。
    $freshDatabase = (new \Illuminate\Database\Connectors\ConnectionFactory(
      new \Illuminate\Container\Container(),
    ))->make(config('database.connections.pgsql'));
    verify(
      $freshDatabase
        ->getPdo()
        ->query('SELECT current_schema()')
        ->fetchColumn() === $schema,
      '新数据库连接未继承 schema',
    );
    $freshRedis = (new \Illuminate\Redis\RedisManager(
      new \Illuminate\Container\Container(),
      'phpredis',
      config('redis'),
    ))->connection();
    verify(
      $freshRedis->client()->getOption(\Redis::OPT_PREFIX) === $prefix,
      '新 Redis 连接未继承 prefix',
    );
    $freshDatabase->disconnect();
    $freshRedis->client()->close();

    $oldUploadDirectory = HttpProtocol::uploadTmpDir();
    HttpProtocol::uploadTmpDir($root . '/runtime/multipart');
    $internal = new ResponseContract(
      Yaml::parseFile($source . '/api/generated/openapi.internal.yaml'),
    );
    $public = new ResponseContract(
      Yaml::parseFile($source . '/api/generated/openapi.public.yaml'),
    );
    $internal->regressionChecks();
    echo "PASS response checker: real schema rejection, object/array, required, union, additionalProperties, unknown semantics\n";

    class CapturingConnection extends TcpConnection
    {
      public ?Response $captured = null;
      public function __construct(private string $remoteIp = '127.0.0.1') {}
      public function send(mixed $sendBuffer, bool $raw = false): bool|null
      {
        verify($sendBuffer instanceof Response, 'HTTP 管线未返回 Response');
        verify($this->captured === null, '一次请求发送多次');
        $this->captured = $sendBuffer;
        return true;
      }
      public function close(mixed $data = null, bool $raw = false): void
      {
        $this->send($data, $raw);
      }
      public function getRemoteIp(): string
      {
        return $this->remoteIp;
      }
      public function getRemotePort(): int
      {
        return 32000;
      }
      public function getLocalIp(): string
      {
        return '127.0.0.1';
      }
      public function getLocalPort(): int
      {
        return 8787;
      }
      public function __destruct() {}
    }

    class WebsiteHttp
    {
      public int $requests = 0;
      public function __construct(
        private Http $app,
        private ResponseContract $internal,
        private ResponseContract $public,
      ) {}

      public function send(
        string $method,
        string $uri,
        mixed $body,
        int $expected = 200,
        ?string $token = null,
        string $type = 'application/json',
        string $audience = 'internal',
        string $ip = '127.0.0.1',
      ): array {
        $raw =
          $type === 'application/json'
            ? json_encode($body, JSON_THROW_ON_ERROR)
            : $body;
        $connection = new CapturingConnection($ip);
        $request = new Request(
          "{$method} {$uri} HTTP/1.1\r\nHost: 127.0.0.1:8787\r\nOrigin: http://127.0.0.1:8080\r\n" .
            "User-Agent: Website HTTP fixture\r\nContent-Type: {$type}\r\n" .
            ($token !== null ? "Authorization: Bearer {$token}\r\n" : '') .
            'Content-Length: ' .
            strlen($raw) .
            "\r\n\r\n" .
            $raw,
        );
        $request->connection = $connection;
        // Timing 的错误诊断包含测试密码/令牌；执行中间件但不将这些值输出到 CI。
        ob_start();
        try {
          $this->app->onMessage($connection, $request);
        } finally {
          ob_end_clean();
        }
        $response = $connection->captured;
        verify($response !== null, "没有响应 {$method} {$uri}");
        verify(
          $response->getStatusCode() === $expected,
          "{$method} {$uri}: expected {$expected}, got {$response->getStatusCode()}: {$response->rawBody()}",
        );
        $this->requests++;
        if ($method === 'OPTIONS') {
          verify($response->rawBody() === '', 'OPTIONS 应返回空体');
          $value = null;
        } else {
          $value = ($audience === 'public'
            ? $this->public
            : $this->internal
          )->response($method, $uri, $response);
        }
        verify(
          $response->getHeader('Access-Control-Allow-Origin') ===
            'http://127.0.0.1:8080',
          '真实 AccessControl 未执行',
        );
        return [$value, $response];
      }

      public function json(
        string $method,
        string $uri,
        array $body = [],
        int $expected = 200,
        ?string $token = null,
        string $audience = 'internal',
      ): mixed {
        [$value] = $this->send(
          $method,
          $uri,
          $body,
          $expected,
          $token,
          'application/json',
          $audience,
        );
        return $value->data ?? $value;
      }

      public function multipart(
        string $method,
        string $uri,
        string $field,
        string $name,
        string $bytes,
        array $fields,
        string $token,
        int $expected = 200,
      ): mixed {
        $boundary = 'website-http-' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $key => $value) {
          $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$key}\"\r\n\r\n{$value}\r\n";
        }
        $body .=
          "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"; filename=\"{$name}\"\r\n" .
          "Content-Type: application/octet-stream\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";
        [$value] = $this->send(
          $method,
          $uri,
          $body,
          $expected,
          $token,
          'multipart/form-data; boundary=' . $boundary,
        );
        return $value->data ?? $value;
      }
    }

    $http = new WebsiteHttp(
      new Http(
        Request::class,
        new \Psr\Log\NullLogger(),
        app_path(),
        public_path(),
      ),
      $internal,
      $public,
    );
    $password = bin2hex(random_bytes(12));
    $users = [];
    foreach (['member', 'other', 'banned'] as $name) {
      $users[$name] = (int) Db::table('app_users')->insertGetId([
        'email' => $name . '@website-http.invalid',
        'username' => $name,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'ban' => $name === 'banned' ? 1 : 0,
      ]);
    }
    $http->json('GET', '/api/health');
    $http->send('OPTIONS', '/api/auth/me', [], 200);
    $http->json('GET', '/api/auth/me', [], 401);
    verify(
      config('database.connections.pgsql.schema') === $schema &&
        config('redis.default.prefix') === $prefix,
      'HTTP refresh 丢失隔离配置',
    );
    echo "PASS bootstrap: real routes, global middleware, isolated configuration and fresh connections\n";

    $newEmail = 'invited@website-http.invalid';
    $http->json(
      'POST',
      '/api/auth/invitations/check',
      ['email' => 'member@website-http.invalid'],
      409,
    );
    verify(
      Email::$messages === [] &&
        !Redis::exists('invite:code:member@website-http.invalid'),
      '已注册邮箱产生发信副作用',
    );
    verify(
      $http->json('POST', '/api/auth/invitations/check', ['email' => $newEmail])
        ->status === 'eligible',
      '邀请资格不正确',
    );
    verify(
      $http->json(
        'POST',
        '/api/auth/invitations/code',
        ['email' => $newEmail],
        202,
      )->status === 'accepted',
      '发码未受理',
    );
    verify(
      count(Email::$messages) === 1 && Email::$messages[0]['to'] === $newEmail,
      '邮件替身收件人不正确',
    );
    [$cooldown, $cooldownResponse] = $http->send(
      'POST',
      '/api/auth/invitations/code',
      ['email' => $newEmail],
      429,
    );
    verify(
      (int) $cooldownResponse->getHeader('Retry-After') > 0 &&
        count(Email::$messages) === 1,
      '发码冷却缺少头或重复发信',
    );
    $code = Redis::get('invite:code:' . $newEmail);
    verify(
      is_string($code) && str_contains(Email::$messages[0]['body'], $code),
      '邮件验证码与缓存不一致',
    );
    $grant = $http->json('POST', '/api/auth/invitations/verify', [
      'email' => $newEmail,
      'code' => $code,
    ]);
    verify(
      $grant->status === 'verified' &&
        !Redis::exists('invite:code:' . $newEmail),
      '邀请码未一次性消费',
    );
    $completion = [
      'completionToken' => $grant->completionToken,
      'nickname' => '邀请测试用户',
      'password' => $password,
    ];
    verify(
      $http->json('POST', '/api/auth/invitations/complete', $completion)
        ->status === 'registered',
      '邀请注册未完成',
    );
    verify(
      Db::table('app_users')->where('email', $newEmail)->count() === 1,
      '邀请未创建真实用户',
    );
    $http->json('POST', '/api/auth/invitations/complete', $completion, 401);
    echo "PASS invitations: eligibility, 409, recorded email, 202/429 headers, one-time verification and registration\n";

    $credentials = [
      'email' => 'member@website-http.invalid',
      'password' => $password,
    ];
    $http->json(
      'POST',
      '/api/auth/login',
      $credentials + [
        'captcha' => ['challengeId' => 'invalid', 'answer' => 'bad'],
      ],
      422,
    );
    $http->json(
      'POST',
      '/api/auth/login',
      ['email' => 'banned@website-http.invalid', 'password' => $password],
      403,
    );
    $http->json(
      'POST',
      '/api/auth/login',
      ['email' => $credentials['email'], 'password' => 'wrong'],
      400,
    );
    $captcha = $http->json('POST', '/api/auth/captcha/challenges');
    $answer = Redis::get('captcha:' . $captcha->challengeId);
    verify(is_string($answer) && $answer !== '', '验证码未保存');
    $login = $http->json(
      'POST',
      '/api/auth/login',
      $credentials + [
        'captcha' => [
          'challengeId' => $captcha->challengeId,
          'answer' => strtoupper($answer),
        ],
      ],
    );
    $token = $login->token;
    verify(
      $login->user->id === $users['member'] &&
        !Redis::exists('captcha:' . $captcha->challengeId),
      '登录身份或验证码消费错误',
    );
    $otherToken = $http->json('POST', '/api/auth/login', [
      'email' => 'other@website-http.invalid',
      'password' => $password,
    ])->token;
    verify(
      $http->json('GET', '/api/auth/me', [], 200, $token)->id ===
        $users['member'],
      'me 身份错误',
    );
    $profile = $http->json(
      'PATCH',
      '/api/auth/me',
      ['nickname' => '资料测试', 'qq' => '12345678'],
      200,
      $token,
    );
    verify(
      $profile->username === '资料测试' &&
        Db::table('app_users')->where('id', $users['member'])->value('qq') ===
          '12345678',
      '资料未持久化',
    );
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);
    $png = ob_get_clean();
    imagedestroy($image);
    $avatar = $http->json(
      'POST',
      '/api/auth/me/avatar',
      ['imageBase64' => base64_encode($png), 'mediaType' => 'image/png'],
      200,
      $token,
    )->avatar;
    [$avatarBytes, $avatarResponse] = $http->send('GET', $avatar, []);
    verify(
      substr($avatarBytes, 0, 4) === 'RIFF' &&
        $avatarResponse->getHeader('Content-Type') === 'image/webp',
      '头像非真实 WebP',
    );
    $avatarPath = runtime_path() . '/avatars/' . basename($avatar) . '.webp';
    verify(is_file($avatarPath), '头像未写入隔离目录');
    $http->json(
      'PATCH',
      '/api/auth/me',
      ['avatar' => null, 'qq' => null],
      200,
      $token,
    );
    verify(!is_file($avatarPath), '清除头像未删除文件');
    $http->json('GET', $avatar, [], 404);
    $http->json('GET', '/api/admin/sso-requests', [], 400, $token);
    echo "PASS account: captcha, 400/401/403/422, login, profile, avatar bytes/cleanup, real route-local PlatformAdmin denial\n";

    $receipts = [];
    foreach (['first', 'second', 'foreign'] as $name) {
      $receipts[$name] = NotificationService::uuid();
      Db::table('app_notifications')->insert([
        'uuid' => $receipts[$name],
        'batch_uuid' => NotificationService::uuid(),
        'user_id' => $name === 'foreign' ? $users['other'] : $users['member'],
        'title' => $name,
        'summary_text' => '通知摘要',
        'body_html' => '<p>通知正文</p>',
        'body_text' => '通知正文',
        'created_time' => date('Y-m-d H:i:s'),
        'actions' => '[]',
      ]);
    }
    $summary = $http->json(
      'GET',
      '/api/notifications/summary',
      [],
      200,
      $token,
    );
    verify($summary->unreadCount === 2, '通知摘要未隔离用户');
    $list = $http->json(
      'GET',
      '/api/notifications?view=unread&limit=1',
      [],
      200,
      $token,
    );
    verify(
      count($list->items) === 1 && $list->nextCursor !== null,
      '通知分页缺少 cursor',
    );
    $page = $http->json(
      'GET',
      '/api/notifications?view=unread&limit=1&cursor=' .
        rawurlencode($list->nextCursor),
      [],
      200,
      $token,
    );
    verify(
      count($page->items) === 1 &&
        $page->items[0]->receiptId !== $list->items[0]->receiptId,
      '通知分页重复',
    );
    $detail = $http->json(
      'GET',
      '/api/notifications/' . $receipts['first'],
      [],
      200,
      $token,
    );
    verify(
      $detail->content->html === '<p>通知正文</p>' && $detail->readAt === null,
      '通知详情错误',
    );
    $http->json(
      'GET',
      '/api/notifications/' . $receipts['foreign'],
      [],
      404,
      $token,
    );
    $changed = $http->json(
      'PATCH',
      '/api/notifications/' . $receipts['first'] . '/state',
      ['read' => true, 'expectedRevision' => 0],
      200,
      $token,
    );
    verify(
      $changed->stateRevision === 1 && $changed->unreadCount === 1,
      '通知已读未生效',
    );
    $http->json(
      'PATCH',
      '/api/notifications/' . $receipts['first'] . '/state',
      ['read' => false, 'expectedRevision' => 0],
      409,
      $token,
    );
    $readCommand = [
      'requestId' => NotificationService::uuid(),
      'readThroughToken' => $summary->readThroughToken,
    ];
    verify(
      $http->json(
        'POST',
        '/api/notifications/read-all',
        $readCommand,
        200,
        $token,
      )->affectedCount === 1,
      '全部已读数量错误',
    );
    verify(
      $http->json(
        'POST',
        '/api/notifications/read-all',
        $readCommand,
        200,
        $token,
      )->replayed,
      '全部已读未幂等',
    );
    $preview = $http->json(
      'POST',
      '/api/notifications/clear-preview',
      [],
      200,
      $token,
    );
    verify($preview->itemCount === 2, '清理预览数量错误');
    $clearCommand = [
      'requestId' => NotificationService::uuid(),
      'clearSnapshotToken' => $preview->clearSnapshotToken,
    ];
    verify(
      $http->json(
        'POST',
        '/api/notifications/clear-all',
        $clearCommand,
        200,
        $token,
      )->affectedCount === 2,
      '清理数量错误',
    );
    verify(
      $http->json(
        'POST',
        '/api/notifications/clear-all',
        $clearCommand,
        200,
        $token,
      )->replayed,
      '清理未幂等',
    );
    verify(
      $http->json('GET', '/api/notifications?view=history', [], 200, $token)
        ->items === [],
      '清理后列表非空',
    );
    verify(
      Db::table('app_notifications')
        ->where('user_id', $users['other'])
        ->whereNull('read_at')
        ->whereNull('cleared_at')
        ->count() === 1,
      '跨用户修改了通知',
    );
    $preferences = $http->json(
      'GET',
      '/api/notification-preferences',
      [],
      200,
      $token,
    );
    $preferenceCommand = [
      'expectedRevision' => $preferences->revision,
      'optionalCategories' => [
        'announcement' => false,
        'personal_update' => true,
      ],
    ];
    $preferences = $http->json(
      'PUT',
      '/api/notification-preferences',
      $preferenceCommand,
      200,
      $token,
    );
    verify(
      $preferences->revision === 1 &&
        $preferences->optionalCategories->announcement === false,
      '偏好更新错误',
    );
    $http->json(
      'PUT',
      '/api/notification-preferences',
      $preferenceCommand,
      409,
      $token,
    );
    echo "PASS notifications: pagination/detail/ownership, revisions/409, read-all/clear replay, preferences\n";

    $storageBytes = "website fixture bytes\0\r\n";
    $storageKey = 'website-fixture/file.bin';
    $storagePlan = [
      'key' => $storageKey,
      'size' => strlen($storageBytes),
      'contentType' => 'application/octet-stream',
      'medium' => 'file',
    ];
    verify(
      $http->json(
        'POST',
        '/api/storage/uploads/prepare',
        $storagePlan,
        200,
        $token,
      )->medium === 'file',
      '上传计划未选择 file',
    );
    $http->json(
      'POST',
      '/api/storage/uploads/prepare',
      array_replace($storagePlan, ['size' => StorageService::MAX_BYTES + 1]),
      413,
      $token,
    );
    $http->json(
      'POST',
      '/api/storage/uploads/prepare',
      array_replace($storagePlan, ['medium' => 'cos']),
      503,
      $token,
    );
    $http->json('POST', '/api/storage/uploads/complete', [], 503, $token);
    $uploaded = $http->multipart(
      'POST',
      '/api/storage/uploads',
      'data',
      'fixture.bin',
      $storageBytes,
      [
        'key' => $storageKey,
        'medium' => 'file',
        'contentType' => 'application/octet-stream',
      ],
      $token,
    );
    verify(
      $uploaded->url ===
        'http://127.0.0.1:8787/api/storage/files?key=' .
          rawurlencode($storageKey),
      '上传 URL 错误',
    );
    [$download, $downloadResponse] = $http->send(
      'GET',
      '/api/storage/files?key=' . rawurlencode($storageKey),
      [],
    );
    verify(
      $download === $storageBytes &&
        $downloadResponse->getHeader('X-Content-Type-Options') === 'nosniff' &&
        str_contains(
          $downloadResponse->getHeader('Content-Disposition'),
          'attachment;',
        ) &&
        $downloadResponse->getHeader('Cache-Control') === 'no-store',
      '文件下载 bytes/headers 错误',
    );
    verify(
      file_get_contents(runtime_path() . '/uploads/' . $storageKey) ===
        $storageBytes,
      'file 介质未隔离落盘',
    );
    echo "PASS storage: file prepare/multipart/download, exact bytes/headers, 413 and explicit COS 503\n";

    verify(
      $http->json('GET', '/api/support/feedback/options', [], 200, $token)
        ->available,
      '反馈选项不可用',
    );
    $attachmentId = NotificationService::uuid();
    $submissionKey = NotificationService::uuid();
    $feedbackBytes = "feedback attachment\0\r\n";
    $feedbackCommand = [
      'submissionKey' => $submissionKey,
      'form' => ['kind' => 'request', 'title' => 'HTTP 管线反馈'],
      'attachments' => [
        [
          'id' => $attachmentId,
          'originalName' => '截图 说明.PNG',
          'contentType' => 'application/octet-stream',
          'byteLength' => strlen($feedbackBytes),
          'sha256' => hash('sha256', $feedbackBytes),
        ],
      ],
    ];
    $feedback = $http->json(
      'POST',
      '/api/support/feedback/submissions',
      $feedbackCommand,
      201,
      $token,
    );
    verify(
      $feedback->nextAction === 'upload' && $feedback->revision === 0,
      '反馈创建快照错误',
    );
    verify(
      $http->json(
        'POST',
        '/api/support/feedback/submissions',
        $feedbackCommand,
        200,
        $token,
      )->id === $feedback->id,
      '反馈创建未幂等',
    );
    verify(
      $http->json(
        'GET',
        '/api/support/feedback/submissions/lookup?submissionKey=' .
          $submissionKey,
        [],
        200,
        $token,
      )->id === $feedback->id,
      '反馈恢复错误',
    );
    $feedbackPath = '/api/support/feedback/submissions/' . $feedback->id;
    $http->json('GET', $feedbackPath, [], 200, $token);
    $http->json('GET', $feedbackPath, [], 404, $otherToken);
    $attachmentPath = $feedbackPath . '/attachments/' . $attachmentId;
    $http->multipart(
      'PUT',
      $attachmentPath,
      'file',
      '截图 说明.PNG',
      'incorrect',
      [],
      $token,
      409,
    );
    $feedback = $http->multipart(
      'PUT',
      $attachmentPath,
      'file',
      '截图 说明.PNG',
      $feedbackBytes,
      [],
      $token,
    );
    verify(
      $feedback->revision === 1 && $feedback->nextAction === 'continue',
      '反馈附件状态错误',
    );
    verify(
      $http->multipart(
        'PUT',
        $attachmentPath,
        'file',
        '截图 说明.PNG',
        $feedbackBytes,
        [],
        $token,
      )->revision === 1,
      '附件重传不应递增修订',
    );
    verify(
      file_get_contents(
        public_path() .
          '/uploads/feedback/' .
          $attachmentId .
          '/attachment.bin',
      ) === $feedbackBytes,
      '附件未隔离落盘',
    );
    $http->json(
      'POST',
      $feedbackPath . '/advance',
      ['expectedRevision' => 0, 'intent' => 'continue'],
      409,
      $token,
    );
    $feedback = $http->json(
      'POST',
      $feedbackPath . '/advance',
      ['expectedRevision' => 1, 'intent' => 'continue'],
      200,
      $token,
    );
    verify(
      $feedback->status === 'completed' &&
        $feedback->revision === 2 &&
        $feedback->nextAction === 'done',
      '反馈未完成',
    );
    verify(
      Db::table('app_feedback_submissions')
        ->where('submission_key', $submissionKey)
        ->value('status') === 'completed',
      '反馈未持久化',
    );
    echo "PASS feedback: create/replay/lookup/detail/ownership, real PUT multipart/hash/409, attachment replay, completion\n";

    $clientId = 'website-http-' . bin2hex(random_bytes(8));
    $clientSecret = bin2hex(random_bytes(16));
    Db::table('app_oauth_clients')->insert([
      'client_id' => $clientId,
      'name' => 'Website fixture',
      'type' => 'plugin',
      'client_secret_hash' => hash('sha256', $clientSecret),
      'scopes' => '["user.basic"]',
    ]);
    $deviceCredentials = [
      'client_id' => $clientId,
      'client_secret' => $clientSecret,
    ];
    $device = $http->json(
      'POST',
      '/api/oauth/device/init',
      $deviceCredentials,
      200,
      null,
      'public',
    );
    verify(
      $device->verification_uri_web ===
        'http://127.0.0.1:8080/login?ssoRequestId=' . $device->sso_request_id,
      'SSO Website 公开地址错误',
    );
    $sso = ['ssoRequestId' => $device->sso_request_id];
    verify(
      $http->json('POST', '/api/auth/sso/inspect', $sso)->status === 'pending',
      'SSO 未 pending',
    );
    $http->json('POST', '/api/auth/sso/confirm', $sso, 401);
    $http->json(
      'POST',
      '/api/auth/sso/login',
      $sso +
        $credentials + [
          'captcha' => ['challengeId' => 'invalid', 'answer' => 'bad'],
        ],
      422,
    );
    verify(
      $http->json('POST', '/api/auth/sso/login', $sso + $credentials)
        ->status === 'completed',
      '密码 SSO 未完成',
    );
    verify(
      $http->json('POST', '/api/auth/sso/inspect', $sso)->status ===
        'completed',
      'SSO inspect 未反映完成',
    );
    $http->json('POST', '/api/auth/sso/login', $sso + $credentials, 409);
    $device = $http->json(
      'POST',
      '/api/oauth/device/init',
      $deviceCredentials,
      200,
      null,
      'public',
    );
    $sso = ['ssoRequestId' => $device->sso_request_id];
    verify(
      $http->json('POST', '/api/auth/sso/confirm', $sso, 200, $token)
        ->status === 'completed',
      '会话 SSO 未完成',
    );
    $http->json('POST', '/api/auth/sso/confirm', $sso, 409, $token);
    verify(
      Db::table('app_oauth_grants')
        ->where('user_id', $users['member'])
        ->where('client_id', $clientId)
        ->count() === 1,
      'SSO grant 未持久化或重复',
    );
    $http->json('GET', '/oauth/device', [], 401, null, 'public');
    echo "PASS SSO: real device-init setup, inspect/password/session confirm, 401/409/422; device top navigation remains 401\n";

    $http->json('POST', '/api/auth/logout', [], 200, $token);
    $http->json('GET', '/api/auth/me', [], 401, $token);
    verify(
      Db::table('app_auth_sessions')
        ->where('user_id', $users['member'])
        ->where('status', 'revoked')
        ->count() === 1,
      'logout 未撤销真实会话',
    );
    verify(
      Db::table('app_audit_logs')
        ->where('action', 'feedback.complete')
        ->count() === 1 &&
        Db::table('app_audit_logs')
          ->where('action', 'user.register_invitation')
          ->count() === 1,
      '审计记录缺失',
    );
    // 使用独立请求 IP 验证生产全局限流分支，不改变默认 loopback/debug 配置。
    Redis::setex('rip_192.0.2.1', 30, '5');
    $http->send(
      'GET',
      '/api/auth/me',
      [],
      403,
      $otherToken,
      'application/json',
      'internal',
      '192.0.2.1',
    );
    verify(
      config('database.connections.pgsql.schema') === $schema &&
        Redis::connection()->client()->getOption(\Redis::OPT_PREFIX) ===
          $prefix,
      '结束时资源隔离丢失',
    );
    echo "PASS logout/audit/rate-limit; {$http->requests} real pipeline requests; " .
      count($internal->coverage) .
      ' internal and ' .
      count($public->coverage) .
      " public operation/status pairs\n";
  } catch (Throwable $error) {
    $failure = $error;
  } finally {
    // 各类资源独立清理；其中一项失败不能跳过其余项。
    foreach (
      [
        'Redis' => function () use (&$rawRedis, $prefix, $schema): void {
          verify(
            preg_match('/^website_http_[a-f0-9]{16}$/D', $schema) === 1 &&
              $prefix === $schema . ':',
            'Redis 清理前缀非法',
          );
          if ($rawRedis !== null) {
            foreach ($rawRedis->keys($prefix . '*') as $key) {
              verify(str_starts_with($key, $prefix), 'Redis 清理键越界');
              $rawRedis->del($key);
            }
            verify($rawRedis->keys($prefix . '*') === [], 'Redis 清理未完成');
            $rawRedis->close();
          }
        },
        'PostgreSQL' => function () use (
          &$pdo,
          $schema,
          &$schemaCreated,
        ): void {
          verify(
            preg_match('/^website_http_[a-f0-9]{16}$/D', $schema) === 1,
            'schema 清理名称非法',
          );
          if ($pdo !== null && $schemaCreated) {
            $pdo->exec('SET search_path TO public');
            $pdo->exec('DROP SCHEMA "' . $schema . '" CASCADE');
            $query = $pdo->prepare(
              'SELECT count(*) FROM pg_namespace WHERE nspname = ?',
            );
            $query->execute([$schema]);
            verify((int) $query->fetchColumn() === 0, 'schema 清理未完成');
          }
        },
        'files' => function () use (
          $root,
          $tempParent,
          $schema,
          &$rootCreated,
          &$oldUploadDirectory,
        ): void {
          if (!$rootCreated) {
            return;
          }
          verify(
            preg_match('/^website_http_[a-f0-9]{16}$/D', $schema) === 1 &&
              realpath(dirname($root)) === $tempParent &&
              basename($root) === $schema,
            '临时根目录清理目标非法',
          );
          if ($oldUploadDirectory !== null) {
            HttpProtocol::uploadTmpDir($oldUploadDirectory);
          }
          if (class_exists(\Webman\Context::class, false)) {
            \Webman\Context::destroy();
          }
          if (class_exists(\support\Log::class, false)) {
            foreach (
              \support\Log::channel('default')->getHandlers()
              as $handler
            ) {
              $handler->close();
            }
          }
          gc_collect_cycles();
          removeDirectory($root, $root);
          clearstatcache(true, $root);
          verify(!file_exists($root), '临时根目录清理未完成');
        },
      ]
      as $resource => $cleanup
    ) {
      try {
        $cleanup();
      } catch (Throwable $error) {
        $cleanupErrors[] = $resource . ': ' . $error->getMessage();
      }
    }
  }
  if ($failure !== null) {
    fwrite(STDERR, 'FAIL: ' . $failure->getMessage() . "\n");
  }
  foreach ($cleanupErrors as $error) {
    fwrite(STDERR, 'CLEANUP FAIL: ' . $error . "\n");
  }
  if ($cleanupErrors === [] && $rootCreated) {
    echo "PASS cleanup: Redis namespace empty, PostgreSQL schema absent, private temporary root absent\n";
  }
  if ($failure !== null || $cleanupErrors !== []) {
    exit(1);
  }
}
