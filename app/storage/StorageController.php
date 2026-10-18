<?php

namespace app\storage;

use app\common\exception\ApiException;
use app\common\services\StorageService;
use support\Request;
use support\Response;

/**
 * 通用内容存储（用户侧, /api/storage/*）
 *
 * 本部署只启用 file 介质（本地磁盘）: prepare 永远返回 file 分支;
 * cos 直传未配置（complete 恒 STORAGE_UNAVAILABLE）; files 公开读。
 */
class StorageController
{
  /** files 为公开读; 其余需登录 */
  protected $noNeedLogin = ['files'];

  /**
   * 上传计划（file 介质: 只返回字节上限, 不签发凭据）
   */
  public function prepare(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["key", "size", "contentType"])) {
      throw new ApiException("参数不匹配");
    }
    if (!StorageService::validKey((string) $post['key'])) {
      throw new ApiException("存储路径非法");
    }
    if (!StorageService::validContentType((string) $post['contentType'])) {
      throw new ApiException("contentType 非法");
    }
    if ((int) $post['size'] > StorageService::MAX_BYTES) {
      throw new ApiException("文件超过大小上限", 413);
    }
    $medium = (string) ($post['medium'] ?? 'file');
    if ($medium === 'cos') {
      // 未配置 COS: 明确拒绝, 不回退（契约 STORAGE_UNAVAILABLE）
      throw new ApiException("cos 介质未启用", 503);
    }
    if ($medium !== 'file') {
      throw new ApiException("medium 只支持 file/cos");
    }
    return success(['medium' => 'file', 'maxBytes' => StorageService::MAX_BYTES]);
  }

  /**
   * 服务端中转上传（multipart: data 文件 + key/medium/contentType 文本字段）
   */
  public function upload(Request $request): Response
  {
    $key = (string) $request->post('key', '');
    if (!StorageService::validKey($key)) {
      throw new ApiException("存储路径非法");
    }
    $medium = (string) ($request->post('medium', 'file') ?: 'file');
    if ($medium !== 'file') {
      throw new ApiException("仅支持 file 介质", 503);
    }

    $file = $request->file('data');
    if (!$file || !$file->isValid()) {
      throw new ApiException("缺少 data 文件部分");
    }
    if ($file->getSize() > StorageService::MAX_BYTES) {
      throw new ApiException("文件超过大小上限", 413);
    }
    $contentType = (string) ($request->post('contentType', '') ?: ($file->getUploadMimeType() ?: 'application/octet-stream'));
    if (!StorageService::validContentType($contentType)) {
      throw new ApiException("contentType 非法");
    }

    // 读入校验大小（不信任 Content-Length）, 写入指定 key
    $bytes = (string) file_get_contents($file->getRealPath());
    if (strlen($bytes) > StorageService::MAX_BYTES) {
      throw new ApiException("文件超过大小上限", 413);
    }
    StorageService::put($key, $bytes);

    // URL 基于当前请求的 Host 生成（浏览器从哪个入口进来就从哪读, 不引入第二跳）
    $scheme = $request->header('x-forwarded-proto') === 'https' ? 'https' : 'http';
    $host = (string) ($request->header('host') ?: '127.0.0.1:8787');
    return success(['url' => "{$scheme}://{$host}/api/storage/files?key=" . rawurlencode($key)]);
  }

  /**
   * COS 直传确认（本部署未启用）
   */
  public function complete(Request $request): Response
  {
    throw new ApiException("cos 介质未启用", 503);
  }

  /**
   * 公开读取（octet-stream + attachment, 禁嗅探）
   */
  public function files(Request $request): Response
  {
    $key = (string) $request->get('key', '');
    $bytes = StorageService::get($key);
    if ($bytes === null) {
      return fail("文件不存在", 404);
    }
    $name = basename($key);
    return (new Response(200, [
      'Content-Type' => 'application/octet-stream',
      'Content-Disposition' => 'attachment; filename="' . addslashes($name) . '"',
      'X-Content-Type-Options' => 'nosniff',
      'Cache-Control' => 'no-store',
    ], $bytes));
  }
}
