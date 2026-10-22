<?php

require_once __DIR__ . '/../vendor/autoload.php';

use support\LocalDevelopment\RuntimeConfiguration;
use support\LocalDevelopment\RuntimeFailure;

$check = function ($condition, $message) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$root =
  sys_get_temp_dir() .
  '/exwebsite-native-configuration-' .
  bin2hex(random_bytes(8));
mkdir($root);
$keys = [
  'SERVER_HOST',
  'SERVER_PORT',
  'USER_WEB_URL',
  'EXWEBSITE_DEV_LISTEN',
  'EXWEBSITE_DEV_WEB_URL',
  'EXWEBSITE_DEV_RUN_ID',
  'EXWEBSITE_DOCKER',
];
$saved = [];
foreach ($keys as $key) {
  $saved[$key] = getenv($key);
  putenv($key);
}
try {
  putenv('EXWEBSITE_DEV_LISTEN=http://127.0.0.1:9999');
  putenv('EXWEBSITE_DEV_WEB_URL=http://127.0.0.1:8080');
  putenv('EXWEBSITE_DEV_RUN_ID=old-run');
  file_put_contents($root . '/.env', "SERVER_HOST=0.0.0.0\nSERVER_PORT=8788\n");
  file_put_contents($root . '/.env.local', "SERVER_PORT=8899\n");
  $line = RuntimeConfiguration::nativeConfigurationLine($root);
  $check(
    $line ===
      'EXWEBSITE_NATIVE_CONFIGURATION {"listen":"http://127.0.0.1:8899"}',
    '原生配置应使用本地覆盖端口与回环报告地址',
  );
  $check(
    getenv('EXWEBSITE_DEV_RUN_ID') === false &&
      RuntimeConfiguration::current()->listen() === 'http://0.0.0.0:8899',
    '预检应清除开发上下文而保留普通绑定主机',
  );
  unlink($root . '/.env.local');
  $check(
    RuntimeConfiguration::nativeConfigurationLine($root) ===
      'EXWEBSITE_NATIVE_CONFIGURATION {"listen":"http://127.0.0.1:8788"}',
    '单一 .env 应生效',
  );
  unlink($root . '/.env');
  putenv('SERVER_PORT');
  $check(
    RuntimeConfiguration::nativeConfigurationLine($root) ===
      'EXWEBSITE_NATIVE_CONFIGURATION {"listen":"http://127.0.0.1:8787"}',
    '未设置端口时应使用普通默认值',
  );
  file_put_contents($root . '/.env', "SERVER_PORT=80\n");
  try {
    RuntimeConfiguration::nativeConfigurationLine($root);
    throw new RuntimeException('无效端口必须失败');
  } catch (RuntimeFailure $error) {
    $check(str_contains($error->getMessage(), '端口'), '应报告端口错误');
  }
  file_put_contents($root . '/.env', "EXWEBSITE_NATIVE_RUN_ID=injected\n");
  try {
    RuntimeConfiguration::nativeConfigurationLine($root);
    throw new RuntimeException('环境文件不可注入 nonce');
  } catch (RuntimeFailure $error) {
    $check(
      str_contains($error->getMessage(), 'EXWEBSITE_NATIVE_RUN_ID'),
      '应拒绝私有 nonce',
    );
  }
  putenv('SERVER_PORT=8788');
  $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
  $process = proc_open(
    [PHP_BINARY, __DIR__ . '/../scripts/native-configuration.php'],
    [['file', $null, 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
    dirname(__DIR__),
    null,
    ['bypass_shell' => true],
  );
  $check(is_resource($process), '无法执行原生配置 CLI');
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $code = proc_close($process);
  $check(
    $code === 0 &&
      $stderr === '' &&
      preg_match(
        '/^EXWEBSITE_NATIVE_CONFIGURATION \{"listen":"http:\/\/127\.0\.0\.1:[0-9]+"\}\r?\n$/D',
        $stdout,
      ),
    'CLI 应只输出一行公开配置 JSON',
  );
  echo "native configuration tests passed\n";
} finally {
  foreach ($keys as $key) {
    $saved[$key] === false ? putenv($key) : putenv($key . '=' . $saved[$key]);
  }
  @unlink($root . '/.env');
  @unlink($root . '/.env.local');
  rmdir($root);
}
