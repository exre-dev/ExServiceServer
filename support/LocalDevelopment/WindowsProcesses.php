<?php

namespace support\LocalDevelopment;

use Throwable;

/** Windows 包装层只管理自己通过 proc_open 创建的 PHP 子进程。 */
class WindowsProcesses
{
  public static function start(
    array $files,
    $binary = PHP_BINARY,
    $directory = null,
  ) {
    $children = [];
    try {
      foreach ($files as $file) {
        $resource = proc_open(
          [$binary, $file, '-q'],
          [STDIN, STDOUT, STDERR],
          $pipes,
          $directory,
          null,
          ['bypass_shell' => true],
        );
        if (!is_resource($resource)) {
          throw new RuntimeFailure('无法启动 PHP worker');
        }
        $children[] = $resource;
      }
      return $children;
    } catch (Throwable $error) {
      self::stop($children);
      throw $error;
    }
  }

  public static function failure(array $children)
  {
    foreach ($children as $resource) {
      $status = proc_get_status($resource);
      if (!$status['running']) {
        return $status['exitcode'] > 0 ? $status['exitcode'] : 1;
      }
    }
    return null;
  }

  public static function stop(
    array $children,
    array $taskkillCommand = ['taskkill'],
  ) {
    $deadline = microtime(true) + 5;
    $running = [];
    foreach ($children as $resource) {
      if (!is_resource($resource)) {
        continue;
      }
      $status = proc_get_status($resource);
      if ($status['running']) {
        $running[] = ['resource' => $resource, 'pid' => $status['pid']];
      }
    }
    $treeKilled = true;
    if ($running && DIRECTORY_SEPARATOR === '\\') {
      $command = array_merge($taskkillCommand, ['/F', '/T']);
      foreach ($running as $child) {
        $command[] = '/PID';
        $command[] = (string) $child['pid'];
      }
      try {
        $killer = @proc_open(
          $command,
          [['file', 'NUL', 'r'], ['file', 'NUL', 'w'], ['file', 'NUL', 'w']],
          $pipes,
          null,
          null,
          ['bypass_shell' => true],
        );
      } catch (Throwable $error) {
        $killer = false;
      }
      $treeKilled = false;
      if (is_resource($killer)) {
        $killDeadline = $deadline - 0.5;
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
    } elseif ($running) {
      foreach ($running as $child) {
        @proc_terminate($child['resource']);
      }
    }
    if (!$treeKilled) {
      foreach ($running as $child) {
        if (proc_get_status($child['resource'])['running']) {
          @proc_terminate($child['resource']);
        }
      }
    }
    foreach ($children as $resource) {
      if (!is_resource($resource)) {
        continue;
      }
      do {
        $status = proc_get_status($resource);
        if (!$status['running']) {
          break;
        }
        usleep(50000);
      } while (microtime(true) < $deadline);
      if ($status['running']) {
        throw new RuntimeFailure('PHP worker 未在 5 秒内退出');
      }
      proc_close($resource);
    }
    if (!$treeKilled) {
      throw new RuntimeFailure('PHP worker 进程树未能在 5 秒内确认回收');
    }
  }
}
