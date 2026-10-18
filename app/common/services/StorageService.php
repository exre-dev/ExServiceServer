<?php

namespace app\common\services;

use app\common\exception\ApiException;

/**
 * 本地文件存储服务（storage file 介质 + 反馈附件落盘共用）
 *
 * 上传根目录 runtime/uploads/；key 按契约 StorageKey 规则校验
 * （小写分段、拒绝 Windows 保留名、解析后必须仍在根目录内）。
 */
class StorageService
{
    /** 上传大小上限（20MiB, 与契约 fileBytes 上限一致） */
    public const MAX_BYTES = 20971520;

    /** Windows 保留设备名（逐段拒绝） */
    private const RESERVED = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    public static function root(): string
    {
        // realpath 归一分隔符: Windows 下 runtime_path() 带尾斜杠且与 realpath 输出混合, 越界比较会误判
        $dir = rtrim(runtime_path(), '/\\') . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return (string) realpath($dir);
    }

    /**
     * 校验存储 key（契约 ^[a-z0-9](...)(/...)*$ + 保留名 + 越界检查）
     */
    public static function validKey(string $key): bool
    {
        if ($key === '' || strlen($key) > 240) {
            return false;
        }
        if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9_-])?(?:\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9_-])?)*$/', $key)) {
            return false;
        }
        foreach (explode('/', $key) as $segment) {
            $base = rtrim($segment, '.');
            if (in_array(strtolower($base), self::RESERVED, true)) {
                return false;
            }
        }
        // 已存在的路径必须仍在根目录内（防越界; 不存在的目标由 put 时 mkdir 创建）
        $real = realpath(self::root() . '/' . $key);
        if ($real !== false && !str_starts_with($real, self::root() . DIRECTORY_SEPARATOR)) {
            return false;
        }
        return true;
    }

    /**
     * 写入文件（返回相对 key）
     */
    public static function put(string $key, string $bytes): void
    {
        if (!self::validKey($key)) {
            throw new ApiException("存储路径非法");
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ApiException("文件超过大小上限", 413);
        }
        $path = self::root() . '/' . $key;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $bytes);
    }

    /**
     * 读取文件（返回 null 表示不存在）
     */
    public static function get(string $key): ?string
    {
        if (!self::validKey($key)) {
            return null;
        }
        $path = self::root() . '/' . $key;
        if (!is_file($path)) {
            return null;
        }
        return (string) file_get_contents($path);
    }

    /**
     * 删除文件（静默）
     */
    public static function delete(string $key): void
    {
        if (self::validKey($key) && $key !== '') {
            $path = self::root() . '/' . $key;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * 内容类型合法性（无参数 type/subtype）
     */
    public static function validContentType(string $contentType): bool
    {
        return (bool) preg_match("/^[A-Za-z0-9!#\$%&'*+.^_`|~-]+\/[A-Za-z0-9!#\$%&'*+.^_`|~-]+$/", $contentType)
            && strlen($contentType) <= 127;
    }
}
