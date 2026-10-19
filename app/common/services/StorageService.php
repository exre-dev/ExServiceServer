<?php

namespace app\common\services;

use app\common\exception\ApiException;

/**
 * 本地文件存储服务（storage file 介质 + 反馈附件落盘共用）
 *
 * 通用上传根目录 runtime/uploads/，反馈附件使用 public/uploads/；key 按契约 StorageKey 规则校验
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

    public static function root(bool $public = false): string
    {
        // realpath 归一分隔符，避免 Windows 混合分隔符造成越界误判。
        $dir = rtrim($public ? public_path() : runtime_path(), '/\\') . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiException("无法创建上传目录", 503);
        }
        $root = realpath($dir);
        if ($root === false) {
            throw new ApiException("上传目录不可用", 503);
        }
        return $root;
    }

    /**
     * 校验存储 key（契约 ^[a-z0-9](...)(/...)*$ + 保留名 + 越界检查）
     */
    public static function validKey(string $key, bool $public = false): bool
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
        $root = self::root($public);
        $real = realpath($root . '/' . $key);
        if ($real !== false && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return false;
        }
        return true;
    }

    /**
     * 写入文件（public=true 时保存到 public/uploads）
     */
    public static function put(string $key, string $bytes, bool $public = false): void
    {
        if (!self::validKey($key, $public)) {
            throw new ApiException("存储路径非法");
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ApiException("文件超过大小上限", 413);
        }
        $root = self::root($public);
        $path = $root . '/' . $key;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiException("无法创建附件目录", 503);
        }
        $realDir = realpath($dir);
        if ($realDir === false || ($realDir !== $root && !str_starts_with($realDir, $root . DIRECTORY_SEPARATOR))) {
            throw new ApiException("存储路径非法");
        }
        if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new ApiException("文件保存失败, 请稍后重试", 503);
        }
    }

    /**
     * 读取文件（返回 null 表示不存在）
     */
    public static function get(string $key, bool $public = false): ?string
    {
        if (!self::validKey($key, $public)) {
            return null;
        }
        $path = self::root($public) . '/' . $key;
        if (!is_file($path)) {
            return null;
        }
        return (string) file_get_contents($path);
    }

    /**
     * 删除文件（静默）
     */
    public static function delete(string $key, bool $public = false): void
    {
        if (self::validKey($key, $public) && $key !== '') {
            $path = self::root($public) . '/' . $key;
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
