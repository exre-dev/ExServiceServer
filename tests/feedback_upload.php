<?php

// 独立进程测试：数据库查询使用内存替身，实际执行 multipart 解析、控制器和磁盘写入。
namespace tests\feedback {
    class Rows
    {
        public function __construct(private array $rows) {}

        public function where(string $field, mixed $value): self
        {
            $this->rows = array_values(array_filter($this->rows, fn ($row) => $row->$field === $value));
            return $this;
        }

        public function first(): mixed { return $this->rows[0] ?? null; }
        public function orderBy(string $field): self { return $this; }
        public function get(): \Illuminate\Support\Collection { return new \Illuminate\Support\Collection($this->rows); }
    }
}

namespace app\common\model {
    class FeedbackModel
    {
        public static array $records = [];
        public int $writes = 0;
        public function __construct(private array $attributes) {}
        public function __get(string $name): mixed { return $this->attributes[$name] ?? null; }
        public function __set(string $name, mixed $value): void { $this->attributes[$name] = $value; }
        public function save(): void { $this->writes++; }
        public static function where(string $field, mixed $value): \tests\feedback\Rows
        {
            return (new \tests\feedback\Rows(static::$records))->where($field, $value);
        }
    }

    class FeedbackAttachmentModel extends FeedbackModel
    {
        public static array $records = [];
    }
}

namespace {
    chdir(dirname(__DIR__));
    require dirname(__DIR__) . '/vendor/autoload.php';
    require dirname(__DIR__) . '/app/functions.php';

    use app\common\exception\ApiException;
    use app\common\model\FeedbackAttachmentModel;
    use app\common\model\FeedbackModel;
    use app\common\services\StorageService;
    use app\support\FeedbackController;
    use support\Request;
    use Workerman\Connection\TcpConnection;
    use Workerman\Protocols\Http;

    function check(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }

    function uuid(): string
    {
        return bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-4' . bin2hex(random_bytes(2))[0] . 'ab-8abc-' . bin2hex(random_bytes(6));
    }

    function fixture(string $name, string $bytes): array
    {
        $row = new FeedbackModel([
            'id' => 1, 'uuid' => uuid(), 'user_id' => 42, 'status' => 'receiving', 'revision' => 0,
            'submission_key' => uuid(), 'form' => ['kind' => 'request', 'title' => '上传测试'],
            'created_time' => '2026-10-19 12:00:00', 'receive_expires_at' => '2026-10-20 12:00:00',
        ]);
        $attachment = new FeedbackAttachmentModel([
            'id' => 1, 'uuid' => uuid(), 'submission_id' => 1, 'ordinal' => 0,
            'original_name' => $name, 'content_type' => 'application/octet-stream',
            'byte_length' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => 'expected',
        ]);
        FeedbackModel::$records = [$row];
        FeedbackAttachmentModel::$records = [$attachment];
        return [$row, $attachment];
    }

    function upload(FeedbackModel $row, FeedbackAttachmentModel $attachment, string $bytes, int $uid = 42): array
    {
        $boundary = 'feedback-test-' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$attachment->original_name}\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";
        $request = new Request("PUT /api/support/feedback/submissions/{$row->uuid}/attachments/{$attachment->uuid} HTTP/1.1\r\n"
            . "Host: localhost\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}");
        $request->uid = $uid;
        $response = (new FeedbackController())->attachment_upload($request, $row->uuid, $attachment->uuid);
        check($response->getStatusCode() === 200, '上传应成功');
        return json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    $tempDir = __DIR__ . '/tmp/feedback-' . bin2hex(random_bytes(8));
    mkdir($tempDir, 0755, true);
    $oldTempDir = Http::uploadTmpDir();
    Http::uploadTmpDir($tempDir);
    $keys = [];
    try {
        foreach (['截图 说明.PNG', 'Crash Log 01.TXT', '中文反馈.zip', '../../payload.PHP'] as $name) {
            $bytes = "attachment bytes\0\r\n";
            [$row, $attachment] = fixture($name, $bytes);
            $key = 'feedback/' . $attachment->uuid . '/attachment.bin';
            $keys[] = $key;
            $result = upload($row, $attachment, $bytes);
            check($attachment->storage_key === $key, '磁盘 key 不应包含原始文件名');
            check(StorageService::get($key, public: true) === $bytes, '文件必须完整写入 public/uploads');
            check(StorageService::get($key) === null, '新反馈附件不应写入 runtime/uploads');
            check($result['attachments'][0]['originalName'] === $name, '应保留原始文件名');
            check($result['nextAction'] === 'continue' && $result['revision'] === 1, '上传后应允许继续提交');
            upload($row, $attachment, $bytes);
            check($row->writes === 1 && $attachment->writes === 1, '重复上传不能重复更新状态');
        }

        foreach (['hash', 'size', 'owner', 'write'] as $failure) {
            [$row, $attachment] = fixture('错误测试.PNG', 'expected');
            $key = 'feedback/' . $attachment->uuid . '/attachment.bin';
            $keys[] = $key;
            if ($failure === 'size') $attachment->byte_length = 1;
            if ($failure === 'write') {
                $dir = StorageService::root(true) . '/feedback/' . $attachment->uuid;
                mkdir($dir, 0755, true);
                mkdir($dir . '/attachment.bin');
            }
            try {
                upload($row, $attachment, $failure === 'hash' ? 'changed' : 'expected', $failure === 'owner' ? 7 : 42);
                throw new \RuntimeException('非法或写入失败的附件不应成功');
            } catch (ApiException $error) {
                check($error->getCode() === match ($failure) { 'owner' => 404, 'write' => 503, default => 409 }, '错误状态码不正确');
            }
            check($attachment->status === 'expected' && $row->revision === 0, '失败不能标记为已上传');
            if ($failure === 'write') {
                rmdir($dir . '/attachment.bin');
                upload($row, $attachment, 'expected');
                check($attachment->status === 'uploaded', '写入故障排除后应可重试');
            }
        }

        $bytes = str_repeat('x', StorageService::MAX_BYTES);
        [$row, $attachment] = fixture('20 MiB.LOG', $bytes);
        $keys[] = 'feedback/' . $attachment->uuid . '/attachment.bin';
        upload($row, $attachment, $bytes);
        check($attachment->status === 'uploaded', '20MiB 上限内的文件应可上传');
        unset($bytes);

        $bytes = str_repeat('x', StorageService::MAX_BYTES + 1);
        [$row, $attachment] = fixture('too-large.log', $bytes);
        try {
            upload($row, $attachment, $bytes);
            throw new \RuntimeException('超过 20MiB 的附件不应成功');
        } catch (ApiException $error) {
            check($error->getCode() === 413, '超过文件大小上限应返回 413');
        }
        check($attachment->status === 'expected' && $row->revision === 0, '超限文件不能标记为已上传');
        unset($bytes);

        $server = require dirname(__DIR__) . '/config/server.php';
        $connection = new class extends TcpConnection {
            public array $rejected = [];
            public function __construct() {}
            public function end(mixed $data = null, bool $raw = false): void { $this->rejected[] = $data; }
            public function __destruct() {}
        };
        $connection->maxPackageSize = $server['max_package_size'];
        $packet = "PUT /api/support/feedback/submissions/test/attachments/test HTTP/1.1\r\nHost: localhost\r\nContent-Length: "
            . (StorageService::MAX_BYTES + 4096) . "\r\n\r\n";
        check(Http::input($packet, $connection) > StorageService::MAX_BYTES, '请求包上限必须允许 20MiB 加 multipart 开销');
        check($connection->rejected === [], '允许的上传不能被协议层拒绝');

        echo "反馈上传检查通过：文件名、真实 PUT multipart、20MiB、归属与摘要校验、重复上传、落盘失败重试和 public 保存路径。\n";
    } finally {
        foreach ($keys as $key) {
            StorageService::delete($key, public: true);
            $dir = dirname(StorageService::root(true) . '/' . $key);
            if (is_dir($dir)) rmdir($dir);
        }
        Http::uploadTmpDir($oldTempDir);
        // 仅清理本测试创建的独立临时目录，不触碰其他上传或持久数据。
        $realTempDir = realpath($tempDir);
        $tempRoot = realpath(__DIR__ . '/tmp');
        if ($realTempDir !== false && $tempRoot !== false && str_starts_with($realTempDir, $tempRoot . DIRECTORY_SEPARATOR)) {
            foreach (glob($realTempDir . '/*') as $file) if (is_file($file)) unlink($file);
            rmdir($realTempDir);
        }
    }
}
