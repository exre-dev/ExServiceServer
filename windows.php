<?php
/**
 * Start file for windows
 */
chdir(__DIR__);
if (PHP_VERSION !== '8.4.27') {
  fwrite(STDERR, "PHP 启动失败：本机 PHP 必须为 8.4.27\n");
  exit(1);
}
require_once __DIR__ . '/vendor/autoload.php';

use support\App;
use support\LocalDevelopment\RuntimeConfiguration;
use support\LocalDevelopment\DevelopmentDependencies;
use support\LocalDevelopment\RuntimeFailure;
use support\LocalDevelopment\WindowsProcesses;
use Workerman\Worker;

ini_set('display_errors', 'on');
error_reporting(E_ALL);

if (empty($GLOBALS['exwebsitePhpRuntimeChecked'])) {
  RuntimeConfiguration::clearContext();
}
RuntimeConfiguration::loadEnvironment(base_path());
if (empty($GLOBALS['exwebsitePhpRuntimeChecked'])) {
  RuntimeConfiguration::current();
  try {
    DevelopmentDependencies::checkOnly(
      [DevelopmentDependencies::class, 'probeBounded'],
      DevelopmentDependencies::configuration(),
      microtime(true) + 20,
    );
  } catch (RuntimeFailure $error) {
    fwrite(STDERR, 'PHP 启动失败：' . $error->getMessage() . PHP_EOL);
    exit(1);
  }
}

App::loadAllConfig(['route']);
$nativePort = parse_url(
  RuntimeConfiguration::current()->listen(),
  PHP_URL_PORT,
);
fwrite(
  STDOUT,
  'EXWEBSITE_NATIVE_LISTEN http://127.0.0.1:' . $nativePort . PHP_EOL,
);
fflush(STDOUT);

$errorReporting = config('app.error_reporting');
if (isset($errorReporting)) {
  error_reporting($errorReporting);
}

$runtimeProcessPath = runtime_path() . DIRECTORY_SEPARATOR . '/windows';
$paths = [$runtimeProcessPath, runtime_path('logs'), runtime_path('views')];
foreach ($paths as $path) {
  if (!is_dir($path)) {
    mkdir($path, 0777, true);
  }
}

$processFiles = [];
if (config('server.listen')) {
  $processFiles[] = __DIR__ . DIRECTORY_SEPARATOR . 'start.php';
}
foreach (config('process', []) as $processName => $config) {
  $processFiles[] = write_process_file($runtimeProcessPath, $processName, '');
}

foreach (config('plugin', []) as $firm => $projects) {
  foreach ($projects as $name => $project) {
    if (!is_array($project)) {
      continue;
    }
    foreach ($project['process'] ?? [] as $processName => $config) {
      $processFiles[] = write_process_file(
        $runtimeProcessPath,
        $processName,
        "$firm.$name",
      );
    }
  }
  foreach ($projects['process'] ?? [] as $processName => $config) {
    $processFiles[] = write_process_file(
      $runtimeProcessPath,
      $processName,
      $firm,
    );
  }
}

function write_process_file($runtimeProcessPath, $processName, $firm): string
{
  $processParam = $firm ? "plugin.$firm.$processName" : $processName;
  $configParam = $firm
    ? "config('plugin.$firm.process')['$processName']"
    : "config('process')['$processName']";
  $fileContent = <<<EOF
  <?php
  require_once __DIR__ . '/../../vendor/autoload.php';

  use Workerman\Worker;
  use Workerman\Connection\TcpConnection;
  use Webman\Config;
  use support\App;

  ini_set('display_errors', 'on');
  error_reporting(E_ALL);

  if (is_callable('opcache_reset')) {
      opcache_reset();
  }

  if (!\$appConfigFile = config_path('app.php')) {
      throw new RuntimeException('Config file not found: app.php');
  }
  \$appConfig = require \$appConfigFile;
  if (\$timezone = \$appConfig['default_timezone'] ?? '') {
      date_default_timezone_set(\$timezone);
  }

  App::loadAllConfig(['route']);

  worker_start('$processParam', $configParam);

  if (DIRECTORY_SEPARATOR != "/") {
      Worker::\$logFile = config('server')['log_file'] ?? Worker::\$logFile;
      TcpConnection::\$defaultMaxPackageSize = config('server')['max_package_size'] ?? 10*1024*1024;
  }

  Worker::runAll();

  EOF;
  $processFile =
    $runtimeProcessPath . DIRECTORY_SEPARATOR . "start_$processParam.php";
  file_put_contents($processFile, $fileContent);
  return $processFile;
}

if ($monitorConfig = config('process.monitor.constructor')) {
  $monitorHandler = config('process.monitor.handler');
  $monitor = new $monitorHandler(...array_values($monitorConfig));
}

$shutdownRequested = !empty($GLOBALS['exwebsiteRuntimeCancelled']);
if (function_exists('sapi_windows_set_ctrl_handler')) {
  sapi_windows_set_ctrl_handler(function () use (&$shutdownRequested) {
    $shutdownRequested = true;
    return true;
  });
}

$children = WindowsProcesses::start($processFiles, PHP_BINARY, __DIR__);
try {
  while (!$shutdownRequested) {
    $exitCode = WindowsProcesses::failure($children);
    if ($exitCode !== null) {
      throw new RuntimeFailure(
        'PHP worker 意外退出，退出码 ' . $exitCode,
        $exitCode,
      );
    }
    if (!empty($monitor) && $monitor->checkAllFilesChange()) {
      WindowsProcesses::stop($children);
      $children = [];
      $children = WindowsProcesses::start($processFiles, PHP_BINARY, __DIR__);
    }
    usleep(200000);
  }
} catch (Throwable $error) {
  WindowsProcesses::stop($children);
  throw $error;
}
WindowsProcesses::stop($children);
