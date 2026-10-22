<?php

// 凭据只从父进程 stdin 接收，不进入进程参数、环境或诊断输出。
require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
  $input = file_get_contents('php://stdin');
  $request = json_decode($input, true);
  if (
    !is_array($request) ||
    !in_array($request['name'] ?? null, ['postgres', 'redis'], true) ||
    !is_array($request['target'] ?? null) ||
    !is_numeric($request['deadline'] ?? null)
  ) {
    exit(2);
  }
  $status = \support\LocalDevelopment\DevelopmentDependencies::probe(
    $request['name'],
    $request['target'],
    (float) $request['deadline'],
  );
  echo $status;
} catch (Throwable $error) {
  echo 'protocol';
}
