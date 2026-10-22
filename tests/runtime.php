<?php

// 本测试只使用临时配置和受控探测替身，不读取项目 .env 或连接日常数据库。
require_once __DIR__ . '/../support/LocalDevelopment/RuntimeConfiguration.php';
require_once __DIR__ . '/../support/LocalDevelopment/RuntimeFailure.php';
require_once __DIR__ .
  '/../support/LocalDevelopment/DevelopmentDependencies.php';
require_once __DIR__ . '/../support/LocalDevelopment/WindowsProcesses.php';
require_once __DIR__ . '/../vendor/autoload.php';

use support\LocalDevelopment\DevelopmentDependencies;
use support\LocalDevelopment\RuntimeConfiguration;
use support\LocalDevelopment\WindowsProcesses;

require_once __DIR__ . '/runtime_environment.php';

function check($condition, $message)
{
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function expectFailure($callback, $message)
{
  try {
    $callback();
  } catch (\support\LocalDevelopment\RuntimeFailure $error) {
    return $error->getMessage();
  }
  throw new RuntimeException($message);
}

$root =
  sys_get_temp_dir() . '/exwebsite-runtime-test-' . bin2hex(random_bytes(8));
mkdir($root);
try {
  file_put_contents(
    $root . '/.env',
    "SERVER_HOST=127.0.0.1\nSERVER_PORT=8788\nUSER_WEB_URL=http://127.0.0.1:8081\n",
  );
  $plain = RuntimeConfiguration::fromValues(
    [],
    [
      'SERVER_HOST' => '127.0.0.1',
      'SERVER_PORT' => '8788',
      'USER_WEB_URL' => 'http://127.0.0.1:8081',
    ],
  );
  check($plain->listen() === 'http://127.0.0.1:8788', '独立监听配置错误');
  check($plain->webUrl() === 'http://127.0.0.1:8081', '独立 Web URL 错误');
  check($plain->readyLine() === null, '独立模式不应输出内部监听标识');

  $context = [
    'EXWEBSITE_DEV_LISTEN' => 'http://127.0.0.1:8790',
    'EXWEBSITE_DEV_WEB_URL' => 'http://127.0.0.1:8083',
    'EXWEBSITE_DEV_RUN_ID' => 'run-test-1',
  ];
  $dev = RuntimeConfiguration::fromValues($context, [
    'SERVER_PORT' => '8788',
    'USER_WEB_URL' => 'http://127.0.0.1:8081',
  ]);
  check($dev->listen() === 'http://127.0.0.1:8790', '开发监听覆盖错误');
  check($dev->webUrl() === 'http://127.0.0.1:8083', '开发 Web URL 覆盖错误');
  check(
    $dev->readyLine() ===
      'EXWEBSITE_PHP_READY {"runId":"run-test-1","listen":"http://127.0.0.1:8790"}',
    '监听标识格式错误',
  );
  check(
    $dev->bindConflictLine() ===
      'EXWEBSITE_PHP_BIND_CONFLICT {"runId":"run-test-1","listen":"http://127.0.0.1:8790"}',
    '绑定竞争标识格式错误',
  );
  expectFailure(function () {
    RuntimeConfiguration::fromValues(['EXWEBSITE_DEV_RUN_ID' => 'partial'], []);
  }, '应拒绝部分上下文');
  expectFailure(function () use ($context) {
    RuntimeConfiguration::fromValues(
      array_merge($context, [
        'EXWEBSITE_DEV_LISTEN' => 'http://192.168.1.2:8790',
      ]),
      [],
    );
  }, '应拒绝非回环监听');
  expectFailure(function () {
    RuntimeConfiguration::fromValues([], ['SERVER_PORT' => '80']);
  }, '应拒绝保留端口');
  expectFailure(function () {
    RuntimeConfiguration::fromValues(
      [],
      ['USER_WEB_URL' => 'http://example.com bad'],
    );
  }, '应拒绝公开 origin 空白字符');
  expectFailure(function () {
    RuntimeConfiguration::fromValues(
      [],
      ['USER_WEB_URL' => 'http://example.com\\evil'],
    );
  }, '应拒绝公开 origin 反斜杠');
  check(
    RuntimeConfiguration::fromValues(
      [],
      ['USER_WEB_URL' => 'https://example.com:443'],
    )->webUrl() === 'https://example.com:443',
    '应允许 HTTPS 标准端口',
  );
  check(
    RuntimeConfiguration::fromValues(
      [],
      ['USER_WEB_URL' => 'http://example.com:80'],
    )->webUrl() === 'http://example.com:80',
    '应允许 HTTP 标准端口',
  );
  check(
    RuntimeConfiguration::fromValues([], ['SERVER_HOST' => '::1'])->listen() ===
      'http://[::1]:8787',
    'IPv6 独立监听格式错误',
  );
  file_put_contents(
    $root . '/.env',
    "# reserved key\nEXWEBSITE_DEV_RUN_ID=bad\n",
  );
  expectFailure(function () use ($root) {
    RuntimeConfiguration::assertNoReservedFileKeys($root . '/.env');
  }, '应拒绝 .env 保留字段');
  $secret = 'TRACE_SECRET_' . bin2hex(random_bytes(8));
  file_put_contents($root . '/.env', "POSTGRES_PASSWORD='$secret\n");
  try {
    RuntimeConfiguration::loadEnvironment($root);
    throw new RuntimeException('格式错误的 .env 应失败');
  } catch (\support\LocalDevelopment\RuntimeFailure $error) {
    check(
      strpos($error->getMessage(), $secret) === false,
      '诊断中不得包含 .env 密码',
    );
  }

  $calls = [];
  $probe = function ($name) use (&$calls) {
    $calls[] = $name;
    return 'ready';
  };
  $composeCalls = 0;
  $compose = function () use (&$composeCalls) {
    $composeCalls++;
  };
  DevelopmentDependencies::ensure(
    $probe,
    $compose,
    [
      'postgres' => ['host' => '127.0.0.1', 'port' => 5432],
      'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'password' => ''],
    ],
    microtime(true) + 120,
  );
  check($calls === ['postgres', 'redis'], '应探测两个服务');
  check($composeCalls === 0, '已就绪时不应调用 Compose');

  $started = [];
  $probe = function ($name) use (&$started) {
    return in_array($name, $started, true)
      ? 'ready'
      : ($name === 'postgres'
        ? 'ready'
        : 'refused');
  };
  $compose = function ($services) use (&$started) {
    check($services === ['redis'], '仅应启动缺失服务');
    $started = $services;
    return true;
  };
  DevelopmentDependencies::ensure(
    $probe,
    $compose,
    [
      'postgres' => ['host' => '127.0.0.1', 'port' => 5432],
      'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'password' => ''],
    ],
    microtime(true) + 120,
  );
  $composeCalls = 0;
  $compose = function () use (&$composeCalls) {
    $composeCalls++;
  };
  $message = expectFailure(function () use ($compose) {
    DevelopmentDependencies::ensure(
      function () {
        return 'authentication';
      },
      $compose,
      ['postgres' => ['host' => '127.0.0.1', 'port' => 5432]],
      microtime(true) + 120,
    );
  }, '认证失败应终止');
  check(
    strpos($message, 'authentication') !== false && $composeCalls === 0,
    '认证失败不得调用 Compose',
  );
  $message = expectFailure(function () use ($compose) {
    DevelopmentDependencies::ensure(
      function () {
        return 'refused';
      },
      $compose,
      ['postgres' => ['host' => 'remote.invalid', 'port' => 5432]],
      microtime(true) + 120,
    );
  }, '远端拒绝不可启动 Compose');
  check(
    strpos($message, '回环') !== false && $composeCalls === 0,
    '远端拒绝不得调用 Compose',
  );
  $started = [];
  $probe = function ($name) use (&$started) {
    return in_array($name, $started, true) ? 'ready' : 'refused';
  };
  $compose = function ($services) use (&$started) {
    check($services === ['redis'], '仅应准备缺失的带认证 Redis');
    $started = $services;
  };
  DevelopmentDependencies::ensure(
    $probe,
    $compose,
    [
      'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => 'secret',
      ],
    ],
    microtime(true) + 120,
  );
  check($started === ['redis'], 'Compose 应支持环境文件配置的 Redis 密码');

  $stallScript = $root . '/stall.php';
  $stallStarted = $root . '/stall-started';
  file_put_contents(
    $stallScript,
    '<?php file_put_contents(' .
      var_export($stallStarted, true) .
      ", 'yes'); sleep(30);\n",
  );
  $start = microtime(true);
  expectFailure(function () use ($stallScript, $root) {
    DevelopmentDependencies::runCommand(
      [PHP_BINARY, $stallScript],
      $root,
      microtime(true) + 4,
      '',
    );
  }, '停顿子进程应被限时回收');
  check(is_file($stallStarted), '超时测试必须实际启动受控子进程');
  check(
    microtime(true) - $start < 5,
    '探测或 CLI 回收超时：' . round(microtime(true) - $start, 2) . ' 秒',
  );
  $cancelStart = microtime(true);
  DevelopmentDependencies::setCancellationCheck(function () use ($cancelStart) {
    return microtime(true) - $cancelStart > 0.25;
  });
  try {
    $message = expectFailure(function () use ($stallScript, $root) {
      DevelopmentDependencies::runCommand(
        [PHP_BINARY, $stallScript],
        $root,
        microtime(true) + 6,
        '',
      );
    }, '取消应回收停顿子进程');
    check(
      strpos($message, '取消') !== false && microtime(true) - $cancelStart < 4,
      '取消未及时回收子进程',
    );
  } finally {
    DevelopmentDependencies::setCancellationCheck(null);
  }
  unlink($stallScript);
  unlink($stallStarted);

  $stdinScript = $root . '/stdin.php';
  file_put_contents(
    $stdinScript,
    "<?php echo stream_get_contents(STDIN) === 'private fixture' && getenv('POSTGRES_PASSWORD') === false && getenv('POSTGRES_ADMIN_PASSWORD') === false && getenv('REDIS_PASSWORD') === false && getenv('JWT_SECRET') === false ? 'ready' : 'wrong';\n",
  );
  $savedSecrets = [];
  foreach (
    [
      'POSTGRES_PASSWORD',
      'POSTGRES_ADMIN_PASSWORD',
      'REDIS_PASSWORD',
      'JWT_SECRET',
    ]
    as $key
  ) {
    $savedSecrets[$key] = getenv($key);
    putenv($key . '=fixture-secret');
  }
  try {
    $result = DevelopmentDependencies::runCommand(
      [PHP_BINARY, $stdinScript],
      $root,
      microtime(true) + 6,
      'private fixture',
      true,
    );
    check(
      $result['exitCode'] === 0 && $result['stdout'] === 'ready',
      '受控 CLI 标准输入或继承环境凭据隔离错误',
    );
  } finally {
    foreach ($savedSecrets as $key => $value) {
      $value === false ? putenv($key) : putenv("$key=$value");
    }
  }
  unlink($stdinScript);

  $outputScript = $root . '/output.php';
  file_put_contents(
    $outputScript,
    "<?php while (true) { echo str_repeat('x', 4096); usleep(1000); }\n",
  );
  $start = microtime(true);
  $message = expectFailure(function () use ($outputScript, $root) {
    DevelopmentDependencies::runCommand(
      [PHP_BINARY, $outputScript],
      $root,
      microtime(true) + 6,
      '',
      true,
    );
  }, '无限输出子进程应有界退出');
  check(
    strpos($message, '输出过长') !== false && microtime(true) - $start < 5,
    '无限输出子进程未在截止前回收',
  );
  unlink($outputScript);

  $treePortFile = $root . '/tree-port';
  $treeChildScript = $root . '/tree-child.php';
  $treeParentScript = $root . '/tree-parent.php';
  file_put_contents(
    $treeChildScript,
    <<<'PHP'
    <?php
    $server = stream_socket_server('tcp://127.0.0.1:0');
    file_put_contents($argv[1], (string) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT));
    sleep(30);
    PHP
    ,
  );
  file_put_contents(
    $treeParentScript,
    <<<'PHP'
    <?php
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $child = proc_open([PHP_BINARY, $argv[1], $argv[2]], [['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($child)) { exit(42); }
    sleep(30);
    PHP
    ,
  );
  $start = microtime(true);
  expectFailure(function () use (
    $treeParentScript,
    $treeChildScript,
    $treePortFile,
    $root,
  ) {
    DevelopmentDependencies::runCommand(
      [PHP_BINARY, $treeParentScript, $treeChildScript, $treePortFile],
      $root,
      microtime(true) + 5,
      '',
    );
  }, '进程树超时应有界退出');
  check(is_file($treePortFile), '进程树夹具未实际启动子监听');
  $treePort = (int) file_get_contents($treePortFile);
  $closed = false;
  for ($attempt = 0; $attempt < 15; $attempt++) {
    $connection = @stream_socket_client(
      'tcp://127.0.0.1:' . $treePort,
      $treeError,
      $treeMessage,
      0.1,
    );
    if (!$connection) {
      $closed = true;
      break;
    }
    fclose($connection);
    usleep(50000);
  }
  check(
    $closed && microtime(true) - $start < 7,
    '受控进程树的子监听在超时后仍存活',
  );
  unlink($treePortFile);
  unlink($treeChildScript);
  unlink($treeParentScript);

  $serverScript = $root . '/stall-server.php';
  $portFile = $root . '/stall-port';
  file_put_contents(
    $serverScript,
    <<<'PHP'
    <?php
    $server = stream_socket_server('tcp://127.0.0.1:0');
    file_put_contents($argv[1], (string) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT));
    for ($index = 0; $index < 2; $index++) {
        $client = stream_socket_accept($server, 10);
        if ($index === 0) { fclose($client); } else { sleep(30); }
    }
    PHP
    ,
  );
  foreach (['redis'] as $service) {
    $server = proc_open(
      [PHP_BINARY, $serverScript, $portFile],
      [STDIN, STDOUT, STDERR],
      $serverPipes,
      $root,
      null,
      ['bypass_shell' => true],
    );
    check(is_resource($server), '无法启动停顿协议夹具');
    try {
      $limit = microtime(true) + 3;
      while (!is_file($portFile) && microtime(true) < $limit) {
        usleep(20000);
      }
      check(is_file($portFile), '停顿协议夹具未公布端口');
      $target = [
        'host' => '127.0.0.1',
        'port' => (int) file_get_contents($portFile),
        'database' => 'test',
        'user' => 'test',
        'password' => 'secret',
      ];
      $start = microtime(true);
      expectFailure(function () use ($service, $target) {
        DevelopmentDependencies::probeBounded(
          $service,
          $target,
          microtime(true) + 4,
        );
      }, "$service 停顿连接应被截止时间取消");
      check(microtime(true) - $start < 4, "$service 停顿连接超时");
    } finally {
      WindowsProcesses::stop([$server]);
      @unlink($portFile);
    }
  }
  unlink($serverScript);

  if (DIRECTORY_SEPARATOR === '/') {
    $adapterRoot = $root . '/linux-adapter';
    mkdir($adapterRoot);
    mkdir($adapterRoot . '/config');
    symlink(realpath(__DIR__ . '/../vendor'), $adapterRoot . '/vendor');
    $adapterStart = $adapterRoot . '/start.php';
    file_put_contents(
      $adapterStart,
      '<?php chdir(__DIR__); require __DIR__ . "/vendor/autoload.php"; \\support\\LocalDevelopment\\LocalRuntimeApp::run();',
    );
    file_put_contents(
      $adapterRoot . '/config/app.php',
      "<?php return ['default_timezone' => 'UTC'];\n",
    );
    file_put_contents(
      $adapterRoot . '/config/server.php',
      "<?php return ['pid_file' => runtime_path('webman.pid'), 'stdout_file' => runtime_path('logs/stdout.log'), 'log_file' => runtime_path('logs/workerman.log')];\n",
    );
    file_put_contents(
      $adapterRoot . '/config/process.php',
      "<?php return ['webman' => ['workerClass' => \\support\\LocalDevelopment\\HttpWorker::class, 'listen' => getenv('EXWEBSITE_DEV_LISTEN'), 'count' => 1]];\n",
    );
    $held = stream_socket_server('tcp://127.0.0.1:0');
    check(is_resource($held), '无法建立 Linux 绑定竞争夹具');
    $adapterPort = (int) parse_url(
      stream_socket_get_name($held, false),
      PHP_URL_PORT,
    );
    $adapterListen = 'http://127.0.0.1:' . $adapterPort;
    $adapterEnvironment = [
      'EXWEBSITE_DEV_LISTEN' => $adapterListen,
      'EXWEBSITE_DEV_WEB_URL' => 'http://127.0.0.1:8083',
      'EXWEBSITE_DEV_RUN_ID' => 'linux-adapter-test',
      'PATH' => getenv('PATH') ?: '',
    ];
    $runAdapter = function ($suffix) use (
      $adapterRoot,
      $adapterStart,
      $adapterEnvironment,
    ) {
      $out = $adapterRoot . '/' . $suffix . '.out';
      $err = $adapterRoot . '/' . $suffix . '.err';
      $process = proc_open(
        [PHP_BINARY, $adapterStart, 'start'],
        [['file', '/dev/null', 'r'], ['file', $out, 'w'], ['file', $err, 'w']],
        $pipes,
        $adapterRoot,
        $adapterEnvironment,
        ['bypass_shell' => true],
      );
      check(is_resource($process), '无法启动 Linux Webman adapter 夹具');
      try {
        $limit = microtime(true) + 5;
        do {
          $status = proc_get_status($process);
          if (!$status['running']) {
            break;
          }
          usleep(50000);
        } while (microtime(true) < $limit);
        check(!$status['running'], 'Linux Webman adapter 夹具未及时退出');
        return [
          'code' => $status['exitcode'],
          'stdout' => (string) file_get_contents($out),
        ];
      } finally {
        WindowsProcesses::stop([$process]);
        @unlink($out);
        @unlink($err);
      }
    };
    try {
      $bindResult = $runAdapter('bind');
      check(
        $bindResult['code'] !== 0 &&
          strpos(
            $bindResult['stdout'],
            'EXWEBSITE_PHP_BIND_CONFLICT {"runId":"linux-adapter-test","listen":"' .
              $adapterListen .
              '"}',
          ) !== false,
        'Linux adapter 实际 Workerman 预检查未报告本轮绑定失败 ' .
          json_encode($bindResult),
      );
      file_put_contents(
        $adapterRoot . '/config/app.php',
        "<?php throw new RuntimeException('fixture config error');\n",
      );
      $configResult = $runAdapter('config');
      check(
        $configResult['code'] !== 0 &&
          strpos($configResult['stdout'], 'EXWEBSITE_PHP_BIND_CONFLICT') ===
            false,
        'Linux 配置错误不应报告端口竞争',
      );
      fclose($held);
      $held = null;
      $rebound = @stream_socket_server('tcp://127.0.0.1:' . $adapterPort);
      check(is_resource($rebound), 'Linux adapter 失败后有残留监听');
      fclose($rebound);
    } finally {
      if (is_resource($held)) {
        fclose($held);
      }
      $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
          $adapterRoot,
          FilesystemIterator::SKIP_DOTS,
        ),
        RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink()
          ? rmdir($item->getPathname())
          : unlink($item->getPathname());
      }
      rmdir($adapterRoot);
    }
  }

  if (DIRECTORY_SEPARATOR === '\\') {
    $nodeAvailable = false;
    try {
      $nodeCheck = @proc_open(
        ['node', '--version'],
        [STDIN, ['file', 'NUL', 'w'], ['file', 'NUL', 'w']],
        $nodePipes,
        $root,
        null,
        ['bypass_shell' => true],
      );
      if (is_resource($nodeCheck)) {
        $nodeAvailable = proc_close($nodeCheck) === 0;
      }
    } catch (Throwable $error) {
      /* PHP 单独检出时 Node 为可选的测试夹具。 */
    }
    if ($nodeAvailable) {
      $nodeScript = $root . '/exclusive-listener.cjs';
      $bindPortFile = $root . '/bind-port';
      file_put_contents(
        $nodeScript,
        "const net = require('node:net'); const fs = require('node:fs'); const server = net.createServer(socket => socket.destroy()); server.listen({host:'127.0.0.1',port:0,exclusive:true}, () => fs.writeFileSync(process.argv[2], String(server.address().port)));\n",
      );
      $owner = proc_open(
        ['node', $nodeScript, $bindPortFile],
        [STDIN, STDOUT, STDERR],
        $ownerPipes,
        $root,
        null,
        ['bypass_shell' => true],
      );
      check(is_resource($owner), '无法启动排他监听夹具');
      try {
        $limit = microtime(true) + 3;
        while (!is_file($bindPortFile) && microtime(true) < $limit) {
          usleep(20000);
        }
        check(is_file($bindPortFile), '排他监听夹具未公布端口');
        $bindPort = (int) file_get_contents($bindPortFile);
        $bindScript = $root . '/bind.php';
        $vendor = var_export(
          realpath(__DIR__ . '/../vendor/autoload.php'),
          true,
        );
        file_put_contents(
          $bindScript,
          '<?php require ' .
            $vendor .
            '; (new \\support\\LocalDevelopment\\HttpWorker(getenv("EXWEBSITE_DEV_LISTEN")))->listen();',
        );
        $bindContext = [
          'EXWEBSITE_DEV_LISTEN' => "http://127.0.0.1:$bindPort",
          'EXWEBSITE_DEV_WEB_URL' => 'http://127.0.0.1:8083',
          'EXWEBSITE_DEV_RUN_ID' => 'bind-test',
        ];
        $bindOutput = $root . '/bind-output';
        $bindEnvironment = $bindContext + [
          'PATH' => getenv('PATH') ?: '',
          'SystemRoot' => getenv('SystemRoot') ?: '',
        ];
        $bindProcess = proc_open(
          [PHP_BINARY, $bindScript],
          [
            ['file', 'NUL', 'r'],
            ['file', $bindOutput, 'w'],
            ['file', 'NUL', 'w'],
          ],
          $bindPipes,
          $root,
          $bindEnvironment,
          ['bypass_shell' => true],
        );
        check(is_resource($bindProcess), '无法启动真实 Workerman 绑定夹具');
        try {
          $limit = microtime(true) + 5;
          do {
            $bindStatus = proc_get_status($bindProcess);
            if (!$bindStatus['running']) {
              break;
            }
            usleep(50000);
          } while (microtime(true) < $limit);
          check(!$bindStatus['running'], '真实 Workerman 绑定夹具未及时退出');
          $bindLine = (string) file_get_contents($bindOutput);
          check(
            $bindStatus['exitcode'] !== 0 &&
              strpos(
                $bindLine,
                'EXWEBSITE_PHP_BIND_CONFLICT {"runId":"bind-test","listen":"http://127.0.0.1:' .
                  $bindPort .
                  '"}',
              ) !== false,
            '实际 Workerman 绑定失败未输出本轮竞争标识 ' .
              json_encode([
                'exitCode' => $bindStatus['exitcode'],
                'stdout' => $bindLine,
              ]),
          );
        } finally {
          WindowsProcesses::stop([$bindProcess]);
          @unlink($bindScript);
          @unlink($bindOutput);
        }
      } finally {
        WindowsProcesses::stop([$owner]);
        @unlink($bindPortFile);
        @unlink($nodeScript);
      }
    }
  }

  $previous = [];
  foreach ($context as $key => $value) {
    $previous[$key] = getenv($key);
    putenv("$key=$value");
  }
  $oldWeb = getenv('USER_WEB_URL');
  putenv('USER_WEB_URL=http://127.0.0.1:8081');
  try {
    check(
      RuntimeConfiguration::current()->webUrl() === 'http://127.0.0.1:8083',
      '重载后 Web 地址覆盖失效',
    );
    check(
      require __DIR__ . '/../config/userweb.php'['base_url'] ===
        'http://127.0.0.1:8083',
      '配置消费者未使用开发覆盖',
    );
  } finally {
    foreach ($previous as $key => $value) {
      $value === false ? putenv($key) : putenv("$key=$value");
    }
    $oldWeb === false ? putenv('USER_WEB_URL') : putenv("USER_WEB_URL=$oldWeb");
  }

  if (DIRECTORY_SEPARATOR === '\\') {
    $failureScript = $root . '/failure.php';
    file_put_contents($failureScript, "<?php exit(23);\n");
    $children = WindowsProcesses::start([$failureScript]);
    $limit = microtime(true) + 5;
    do {
      $code = WindowsProcesses::failure($children);
      if ($code !== null) {
        break;
      }
      usleep(50000);
    } while (microtime(true) < $limit);
    check($code === 23, 'Windows 子进程退出码未传播');
    WindowsProcesses::stop($children);
    unlink($failureScript);

    $sleepScript = $root . '/sleep.php';
    file_put_contents($sleepScript, "<?php sleep(30);\n");
    $children = WindowsProcesses::start([$sleepScript, $sleepScript]);
    $start = microtime(true);
    WindowsProcesses::stop($children);
    check(microtime(true) - $start < 6, 'Windows 多子进程清理超时');
    $fakeTaskkill = $root . '/fake-taskkill.php';
    file_put_contents($fakeTaskkill, "<?php sleep(30);\n");
    $children = WindowsProcesses::start([$sleepScript]);
    $start = microtime(true);
    $message = expectFailure(function () use ($children, $fakeTaskkill) {
      WindowsProcesses::stop($children, [PHP_BINARY, $fakeTaskkill]);
    }, '停顿 taskkill 应有界失败');
    check(
      strpos($message, '进程树') !== false && microtime(true) - $start < 6,
      '停顿 taskkill 未在统一截止内返回',
    );
    WindowsProcesses::stop($children);
    unlink($fakeTaskkill);
    unlink($sleepScript);
  }
  echo "runtime tests passed\n";
} finally {
  @unlink($root . '/.env');
  @rmdir($root);
}
