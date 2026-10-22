<?php

namespace support\LocalDevelopment;

use Throwable;
use Workerman\Worker;

/** 仅在 Workerman 的实际 HTTP bind 失败且端口被占时标记本轮竞争。 */
class HttpWorker extends Worker
{
  private static $earlyBindFailure = false;

  /** Mirrors locked Workerman 5.2.3's Linux check, retaining the failing worker identity. */
  protected static function checkPortAvailable(): void
  {
    foreach (static::$workers as $worker) {
      $socketName = $worker->getSocketName();
      if (
        DIRECTORY_SEPARATOR !== '/' ||
        static::$status !== static::STATUS_STARTING ||
        $worker->transport !== 'tcp' ||
        str_starts_with($socketName, 'unix') ||
        str_starts_with($socketName, 'udp')
      ) {
        continue;
      }
      $parts = parse_url($socketName);
      if (!isset($parts['host'], $parts['port'])) {
        continue;
      }
      $address = "tcp://{$parts['host']}:{$parts['port']}";
      set_error_handler(function ($code, $message) {
        throw new \RuntimeException($message);
      });
      try {
        $server = stream_socket_server($address, $code, $message);
        if ($server) {
          fclose($server);
        }
      } catch (Throwable $error) {
        restore_error_handler();
        self::$earlyBindFailure = true;
        $configuration = RuntimeConfiguration::current();
        if (
          $worker instanceof self &&
          $socketName === $configuration->socketListen() &&
          $configuration->readyLine() !== null &&
          self::occupied($socketName)
        ) {
          self::emitBindConflict($configuration);
        }
        throw $error;
      }
      restore_error_handler();
    }
  }

  public static function earlyBindFailed(): bool
  {
    return self::$earlyBindFailure;
  }

  public function listen(bool $autoAccept = true): void
  {
    try {
      parent::listen($autoAccept);
    } catch (Throwable $error) {
      $configuration = RuntimeConfiguration::current();
      if (
        $configuration->readyLine() !== null &&
        $this->getSocketName() === $configuration->socketListen() &&
        self::occupied($configuration->socketListen())
      ) {
        self::emitBindConflict($configuration);
      }
      throw $error;
    }
  }

  private static function emitBindConflict(
    RuntimeConfiguration $configuration,
  ): void {
    fwrite(STDOUT, $configuration->bindConflictLine() . PHP_EOL);
    fflush(STDOUT);
  }

  private static function occupied($listen)
  {
    $address = 'tcp://' . substr($listen, strlen('http://'));
    $errno = 0;
    $message = '';
    $socket = @stream_socket_server($address, $errno, $message);
    if ($socket) {
      fclose($socket);
      return false;
    }
    if (in_array($errno, [98, 10048], true)) {
      return true;
    }
    // Windows 的 stream_socket_server 在排他监听冲突时也可能给出 errno=0。
    // 只有实际 Workerman listen 已抛错且目标当前可建立 TCP 连接，才接受这类结果。
    $client = @stream_socket_client(
      $address,
      $connectError,
      $connectMessage,
      0.2,
    );
    if (!$client) {
      return false;
    }
    fclose($client);
    return true;
  }
}
