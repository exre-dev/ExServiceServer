<?php

namespace app\common\openapi;

use RuntimeException;
use Throwable;

/** 以受限清单维护所有权，先暂存全部文件，发布失败时恢复原文件。 */
final class ArtifactPublisher
{
  public const MANIFEST = '.openapi-manifest.json';
  private const LEGACY = ['openapi.public.yaml', 'openapi.internal.yaml'];

  public function __construct(private string $output)
  {
    $this->output = rtrim(str_replace('\\', '/', $output), '/');
  }

  public function execute(string $mode, array $files): void
  {
    $this->assertRoot();
    foreach (array_keys($files) as $file) {
      $this->assertManaged($file);
    }
    ksort($files, SORT_STRING);
    $owned = $this->owned();
    $manifest =
      json_encode(
        ['version' => 1, 'files' => array_keys($files)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
      ) . "\n";
    $stale = array_values(array_diff($owned, array_keys($files)));
    foreach (self::LEGACY as $file) {
      if (file_exists($this->path($file)) || is_link($this->path($file))) {
        $stale[] = $file;
      }
    }
    $files[self::MANIFEST] = $manifest;
    $targets = array_values(
      array_unique(array_merge(array_keys($files), $stale)),
    );
    // 在任何替换前检查全部目标与父目录，防止后续非法目标造成部分发布。
    foreach ($targets as $file) {
      $this->assertTarget($file);
    }
    $ownership = array_fill_keys($owned, true);
    foreach ($files as $file => $_) {
      if (
        $file !== self::MANIFEST &&
        is_file($this->path($file)) &&
        !isset($ownership[$file])
      ) {
        throw new RuntimeException('OpenAPI 目标已被未受管文件占用: ' . $file);
      }
    }
    if ($mode === 'check') {
      foreach ($files as $file => $bytes) {
        if (
          !is_file($this->path($file)) ||
          file_get_contents($this->path($file)) !== $bytes
        ) {
          throw new RuntimeException('OpenAPI 产物缺失或陈旧: ' . $file);
        }
      }
      if ($stale) {
        throw new RuntimeException(
          'OpenAPI 存在过期受管产物: ' . implode(', ', $stale),
        );
      }
      return;
    }
    $directories = [];
    $stage = null;
    $staged = [];
    $backups = [];
    $written = [];
    $restored = false;
    try {
      $this->directory($this->output, $directories);
      $stage = $this->output . '/.openapi-stage-' . bin2hex(random_bytes(8));
      if (!mkdir($stage)) {
        throw new RuntimeException('无法创建 OpenAPI 暂存目录');
      }
      foreach ($files as $file => $bytes) {
        $temporary = $stage . '/' . count($staged) . '.new';
        $staged[$file] = $temporary;
        if (file_put_contents($temporary, $bytes) !== strlen($bytes)) {
          throw new RuntimeException('无法写入暂存产物: ' . $file);
        }
      }
      foreach ($targets as $file) {
        $this->directory(dirname($this->path($file)), $directories);
      }
      foreach ($targets as $file) {
        // 再核对一次父路径，避免暂存期间被替换成符号链接。
        $this->assertTarget($file);
        $path = $this->path($file);
        if (is_file($path)) {
          $backup = $stage . '/' . count($backups) . '.old';
          if (!@rename($path, $backup)) {
            throw new RuntimeException('无法备份原产物: ' . $file);
          }
          $backups[$file] = $backup;
        }
        if (isset($staged[$file])) {
          if (!@rename($staged[$file], $path)) {
            throw new RuntimeException('无法替换产物: ' . $file);
          }
          $written[] = $file;
        }
      }
      $restored = true;
    } catch (Throwable $exception) {
      foreach (array_reverse($written) as $file) {
        if (!@unlink($this->path($file))) {
          throw new RuntimeException(
            '无法回滚新增产物，原文件保留于 ' . $stage,
            0,
            $exception,
          );
        }
      }
      foreach ($backups as $file => $backup) {
        if (!@rename($backup, $this->path($file))) {
          throw new RuntimeException(
            '无法恢复原产物，备份保留于 ' . $stage,
            0,
            $exception,
          );
        }
      }
      $restored = true;
      throw $exception;
    } finally {
      // 回滚本身失败时保留备份，避免丢失唯一原件；不递归删除任何目录。
      if ($restored) {
        foreach (
          array_merge(array_values($staged), array_values($backups))
          as $temporary
        ) {
          if (is_file($temporary)) {
            unlink($temporary);
          }
        }
        if ($stage !== null && is_dir($stage)) {
          rmdir($stage);
        }
      }
      foreach (array_reverse($directories) as $directory) {
        if (is_dir($directory) && count(scandir($directory)) === 2) {
          rmdir($directory);
        }
      }
    }
  }

  private function path(string $file): string
  {
    return $this->output . '/' . $file;
  }

  private function assertManaged(string $file): void
  {
    if (
      !preg_match(
        '~^(?:(?:public|internal)/(?:openapi\.yaml|(?:paths|schemas)/[a-z][a-z0-9_-]*\.yaml)|generated/openapi\.(?:public|internal)\.yaml)$~D',
        $file,
      )
    ) {
      throw new RuntimeException('非法 OpenAPI 清单路径: ' . $file);
    }
  }

  private function assertRoot(): void
  {
    if (
      $this->output === '' ||
      str_contains($this->output, "\0") ||
      preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*://~', $this->output)
    ) {
      throw new RuntimeException('非法 OpenAPI 输出目录');
    }
    $path = $this->output;
    while (true) {
      if (is_link($path) || (file_exists($path) && !is_dir($path))) {
        throw new RuntimeException('输出目录或父目录不是普通目录: ' . $path);
      }
      $parent = dirname($path);
      if ($parent === $path || $path === '.') {
        break;
      }
      $path = $parent;
    }
  }

  private function assertTarget(string $file): void
  {
    $path = $this->path($file);
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
      throw new RuntimeException('输出路径不是文件: ' . $path);
    }
    $path = dirname($path);
    while ($path !== $this->output) {
      if (is_link($path) || (file_exists($path) && !is_dir($path))) {
        throw new RuntimeException('输出父路径不是目录: ' . $path);
      }
      $path = dirname($path);
    }
  }

  private function owned(): array
  {
    $this->assertTarget(self::MANIFEST);
    $path = $this->path(self::MANIFEST);
    if (!is_file($path)) {
      return [];
    }
    if (filesize($path) > 262144) {
      throw new RuntimeException('OpenAPI 清单过大');
    }
    try {
      $manifest = json_decode(
        file_get_contents($path),
        true,
        32,
        JSON_THROW_ON_ERROR,
      );
    } catch (Throwable $exception) {
      throw new RuntimeException('非法 OpenAPI 清单', 0, $exception);
    }
    if (
      !is_array($manifest) ||
      ($manifest['version'] ?? null) !== 1 ||
      !is_array($manifest['files'] ?? null) ||
      !array_is_list($manifest['files']) ||
      count($manifest['files']) > 1024
    ) {
      throw new RuntimeException('非法 OpenAPI 清单');
    }
    $seen = [];
    foreach ($manifest['files'] as $file) {
      if (!is_string($file)) {
        throw new RuntimeException('非法 OpenAPI 清单路径');
      }
      $this->assertManaged($file);
      if (isset($seen[$file])) {
        throw new RuntimeException('OpenAPI 清单路径重复: ' . $file);
      }
      $seen[$file] = true;
    }
    return array_keys($seen);
  }

  private function directory(string $path, array &$created): void
  {
    if (is_dir($path)) {
      return;
    }
    $parent = dirname($path);
    if ($parent !== $path) {
      $this->directory($parent, $created);
    }
    if (!@mkdir($path) && !is_dir($path)) {
      throw new RuntimeException('无法创建输出目录: ' . $path);
    }
    $created[] = $path;
  }
}
