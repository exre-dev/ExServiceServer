<?php

// 镜像已构建后运行：php tests/container_install.php。仅使用一次性 vendor 卷，不启动服务。
$root = dirname(__DIR__);
$volume = 'exwebsite-php-install-test-' . bin2hex(random_bytes(6));
$image = getenv('EXWEBSITE_PHP_TEST_IMAGE') ?: 'exre-php-local-php:latest';
$protected = [
  'start.php',
  'windows.php',
  'webman',
  'config/plugin/webman-tech/swagger/app.php',
];
$generated = [
  'config/plugin/webman-tech/dto/app.php',
  'config/plugin/webman-tech/swagger/route.php',
];
$before = [];
foreach ($protected as $path) {
  $before[$path] = hash_file('sha256', $root . '/' . $path);
}
foreach ($generated as $path) {
  if (is_file($root . '/' . $path)) {
    throw new RuntimeException('容器安装测试前已有插件生成文件：' . $path);
  }
}

$run = function (array $command) use ($root): int {
  $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
  $process = proc_open(
    $command,
    [['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w']],
    $pipes,
    $root,
    null,
    ['bypass_shell' => true],
  );
  if (!is_resource($process)) {
    throw new RuntimeException('无法启动 Docker 测试命令');
  }
  return proc_close($process);
};

try {
  $status = $run([
    'docker',
    'run',
    '--rm',
    '--volume',
    $root . ':/app',
    '--volume',
    $volume . ':/app/vendor',
    $image,
    'php',
    '-v',
  ]);
  if ($status !== 0) {
    throw new RuntimeException('首次 Composer 安装失败，退出码 ' . $status);
  }
  foreach ($protected as $path) {
    if (hash_file('sha256', $root . '/' . $path) !== $before[$path]) {
      throw new RuntimeException('Composer 安装改写了宿主源码：' . $path);
    }
  }
  foreach ($generated as $path) {
    if (is_file($root . '/' . $path)) {
      throw new RuntimeException(
        'Composer 安装生成了未跟踪的插件配置：' . $path,
      );
    }
  }
  echo "container install source preservation passed\n";
} finally {
  // 卷名由本进程随机生成，清理范围只限本次安装测试。
  $run(['docker', 'volume', 'rm', $volume]);
}
