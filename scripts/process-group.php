<?php

// Linux 后台检查命令独占进程组，使超时或取消能回收本轮 CLI 及其子进程。
if (
  DIRECTORY_SEPARATOR !== '/' ||
  $argc < 2 ||
  !function_exists('posix_setsid')
) {
  exit(127);
}
if (posix_setsid() === -1) {
  exit(127);
}
if (function_exists('pcntl_exec')) {
  pcntl_exec('/usr/bin/env', array_slice($argv, 1));
  exit(127);
}

// Some CLI builds expose POSIX process groups without pcntl_exec. Keep the
// wrapper as group leader and let its direct child inherit that same group.
$child = proc_open(
  array_slice($argv, 1),
  [STDIN, STDOUT, STDERR],
  $pipes,
  null,
  null,
  ['bypass_shell' => true],
);
exit(is_resource($child) ? proc_close($child) : 127);
