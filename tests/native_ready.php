<?php

require_once __DIR__ . '/../vendor/autoload.php';

use app\common\process\Http;
use support\LocalDevelopment\RuntimeConfiguration;
use support\LocalDevelopment\RuntimeFailure;
use Webman\Http\Request;
use Webman\Http\Response;

class NativeSendHarness extends Http
{
  public static function forward($connection, $response, $request): void
  {
    self::send($connection, $response, $request);
  }
}

class NativeSendConnection
{
  public $sent = null;
  public $closed = null;

  public function send($response): void
  {
    $this->sent = $response;
  }
  public function close($response): void
  {
    $this->closed = $response;
  }
}

$check = function ($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$keys = [
  'EXWEBSITE_NATIVE_RUN_ID',
  'EXWEBSITE_DOCKER',
  'SERVER_HOST',
  'SERVER_PORT',
  'EXWEBSITE_DEV_LISTEN',
  'EXWEBSITE_DEV_WEB_URL',
  'EXWEBSITE_DEV_RUN_ID',
];
$saved = [];
foreach ($keys as $key) {
  $saved[$key] = getenv($key);
  putenv($key);
}
$root =
  sys_get_temp_dir() . '/exwebsite-native-ready-' . bin2hex(random_bytes(8));
mkdir($root);
try {
  putenv('SERVER_HOST=0.0.0.0');
  putenv('SERVER_PORT=8788');
  $check(
    RuntimeConfiguration::nativeReadyLine() === null,
    '无本轮 nonce 时不得宣布原生就绪',
  );
  putenv('EXWEBSITE_NATIVE_RUN_ID=native-test_1');
  $expected =
    'EXWEBSITE_NATIVE_READY {"runId":"native-test_1","listen":"http://127.0.0.1:8788"}';
  $check(
    RuntimeConfiguration::nativeReadyLine() === $expected,
    '原生就绪必须携带本轮 nonce 与公开回环端口',
  );
  $events = [];
  Http::afterWorkerStarted(
    function () use (&$events) {
      $events[] = 'initialized';
    },
    function ($line) use (&$events) {
      $events[] = $line;
    },
  );
  $check(
    $events === ['initialized', $expected],
    'worker 初始化完成后才能宣布原生就绪',
  );
  $events = [];
  try {
    Http::afterWorkerStarted(
      function () {
        throw new RuntimeException('bootstrap failed');
      },
      function ($line) use (&$events) {
        $events[] = $line;
      },
    );
    throw new RuntimeException('初始化失败应传播');
  } catch (RuntimeException $error) {
    $check(
      $error->getMessage() === 'bootstrap failed' && !$events,
      '初始化失败不得宣布原生就绪',
    );
  }
  foreach (['bad nonce', str_repeat('a', 129), ''] as $bad) {
    putenv('EXWEBSITE_NATIVE_RUN_ID=' . $bad);
    try {
      RuntimeConfiguration::nativeReadyLine();
      throw new RuntimeException('无效原生 nonce 应被拒绝');
    } catch (RuntimeFailure $error) {
      $check(
        str_contains($error->getMessage(), '运行 ID'),
        '应说明原生 nonce 无效',
      );
    }
  }
  putenv('EXWEBSITE_NATIVE_RUN_ID=native-test_1');
  $send = function ($path, $response, $nonce, $docker = false) {
    $nonce === null
      ? putenv('EXWEBSITE_NATIVE_RUN_ID')
      : putenv('EXWEBSITE_NATIVE_RUN_ID=' . $nonce);
    $docker ? putenv('EXWEBSITE_DOCKER=1') : putenv('EXWEBSITE_DOCKER');
    $connection = new NativeSendConnection();
    $request = new Request(
      "GET $path HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Exwebsite-Native-Run: forged\r\n\r\n",
    );
    NativeSendHarness::forward($connection, $response, $request);
    return $connection;
  };
  $status = new Response(
    200,
    ['Content-Type' => 'application/json'],
    '{"msg":"success","data":{"installed":true}}',
  );
  $owned = $send('/api/installation/status', $status, 'native-test_1');
  $check(
    $owned->sent === $status && $owned->closed === null,
    '应保留 Webman 原发送路径',
  );
  $check(
    $status->getHeader('X-Exwebsite-Native-Run') === 'native-test_1',
    '状态响应必须携带运行环境中的本轮 nonce',
  );
  $check(
    $status->rawBody() === '{"msg":"success","data":{"installed":true}}',
    '不得改变业务响应体',
  );
  foreach (
    [
      ['/api/installation/status', null, false],
      ['/api/installation/status', 'native-test_1', true],
      ['/api/installation/install', 'native-test_1', false],
    ]
    as [$path, $nonce, $docker]
  ) {
    $response = new Response(200, [], 'fixture');
    $sent = $send($path, $response, $nonce, $docker);
    $check(
      $sent->sent === $response &&
        $response->getHeader('X-Exwebsite-Native-Run') === null,
      '非本轮原生状态响应不得注入归属头',
    );
  }
  $plain = $send('/api/installation/status', 'plain response', 'native-test_1');
  $check($plain->sent === 'plain response', '非 Webman Response 应原样转发');
  putenv('EXWEBSITE_DOCKER');
  putenv('EXWEBSITE_NATIVE_RUN_ID=native-test_1');
  foreach (['.env', '.env.local'] as $file) {
    foreach (['EXWEBSITE_NATIVE_RUN_ID', 'EXWEBSITE_DOCKER'] as $key) {
      file_put_contents($root . '/' . $file, $key . "=injected\n");
      try {
        RuntimeConfiguration::loadEnvironment($root);
        throw new RuntimeException($file . ' 应拒绝私有字段 ' . $key);
      } catch (RuntimeFailure $error) {
        $check(str_contains($error->getMessage(), $key), '应指出被拒绝的字段');
      }
      $check(
        getenv('EXWEBSITE_NATIVE_RUN_ID') === 'native-test_1',
        '环境文件不能覆盖继承的 nonce',
      );
      unlink($root . '/' . $file);
    }
  }
  putenv('EXWEBSITE_DOCKER=1');
  $check(
    RuntimeConfiguration::nativeReadyLine() === null,
    '容器模式不得宣布原生就绪',
  );
  echo "native ready tests passed\n";
} finally {
  foreach ($keys as $key) {
    $saved[$key] === false ? putenv($key) : putenv($key . '=' . $saved[$key]);
  }
  @unlink($root . '/.env');
  @unlink($root . '/.env.local');
  rmdir($root);
}
