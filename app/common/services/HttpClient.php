<?php

namespace app\common\services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTTP 请求工具类（基于 Guzzle，同步阻塞模式）
 *
 * 设计要点：
 * 1. 采用「流式链式构建器」模式，每次 Http::make() 返回新实例，避免协程/进程间状态污染
 * 2. 底层 Guzzle Client 静态复用，利用其连接池，避免重复建连开销
 * 3. 同步阻塞：请求期间会占用当前 Worker 进程，适合队列消费者、定时任务、低频外部调用
 * 4. 鉴权完全由业务层通过 header() 手动注入，不做任何自动注入，保持灵活
 *
 * 典型用法：
 *   $res = Http::make()
 *       ->get('https://api.example.com/users')
 *       ->query(['page' => 1])
 *       ->header('Authorization', 'Bearer xxx')
 *       ->timeout(5)
 *       ->send();
 */
class HttpClient
{
    /**
     * 静态复用的 Guzzle 客户端实例
     * 持有连接池，避免每次请求都重新建立 TCP 连接
     */
    private static ?Client $client = null;

    /** @var string 请求方法，默认 GET */
    private string $method = 'GET';

    /** @var string 请求地址（不含 query） */
    private string $url = '';

    /** @var array 请求头，键值对形式 */
    private array $headers = [];

    /** @var array URL query 参数 */
    private array $query = [];

    /** @var mixed 原始 body（非 JSON 时使用） */
    private mixed $body = null;

    /** @var array JSON body 数据 */
    private array $json = [];

    /** @var bool 是否使用 JSON body */
    private bool $isJson = false;

    /** @var array 表单数据（application/x-www-form-urlencoded） */
    private array $form = [];

    /** @var bool 是否使用表单 body */
    private bool $isForm = false;

    /** @var float 超时时间（秒） */
    private float $timeout = 10.0;

    /** @var int 失败重试次数 */
    private int $retry = 0;

    /**
     * 获取（或初始化）Guzzle 客户端
     * 使用静态属性缓存，确保整个进程生命周期内只有一个实例，复用连接池
     *
     * @return Client
     */
    private static function client(): Client
    {
        return self::$client ??= new Client([
            // 总超时时间（秒），包含建连、发送、接收全过程
            'timeout' => 6,
            // 建连超时时间（秒），单独控制 TCP 握手阶段
            'connect_timeout' => 3,
            // 关闭 Guzzle 默认的 4xx/5xx 抛异常行为，交由业务层判断状态码
            'http_errors' => false,
        ]);
    }

    /**
     * 静态入口：每次调用返回一个全新的构建器实例
     *
     * 为什么不复用同一个实例？
     * 构建器是「可变对象」，如果多个请求共享同一实例，后设置的 header/query 会覆盖先前的。
     * 每次 new 一个，保证每个请求的配置互不影响。
     *
     * @return self
     */
    public static function make(): self
    {
        return new self();
    }

    // ==================== 链式配置方法 ====================
    // 以下所有方法都返回 $this，以支持链式调用

    /**
     * 发起 GET 请求
     *
     * @param string $url 请求地址
     * @return self
     */
    public function get(string $url): self
    {
        $this->method = 'GET';
        $this->url = $url;
        return $this;
    }

    /**
     * 发起 POST 请求
     *
     * @param string $url 请求地址
     * @return self
     */
    public function post(string $url): self
    {
        $this->method = 'POST';
        $this->url = $url;
        return $this;
    }

    /**
     * 发起 PUT 请求
     *
     * @param string $url 请求地址
     * @return self
     */
    public function put(string $url): self
    {
        $this->method = 'PUT';
        $this->url = $url;
        return $this;
    }

    /**
     * 发起 DELETE 请求
     *
     * @param string $url 请求地址
     * @return self
     */
    public function delete(string $url): self
    {
        $this->method = 'DELETE';
        $this->url = $url;
        return $this;
    }

    /**
     * 设置单个请求头
     *
     * @param string $key   头名称，如 Authorization
     * @param string $value 头值
     * @return self
     */
    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * 批量设置请求头（与已有头合并，同名覆盖）
     *
     * @param array $headers 键值对数组
     * @return self
     */
    public function headers(array $headers): self
    {
        $this->headers = [...$this->headers, ...$headers];
        return $this;
    }

    /**
     * 设置 URL query 参数（可多次调用累加）
     *
     * @param array $query 键值对数组
     * @return self
     */
    public function query(array $query): self
    {
        $this->query = array_merge($this->query, $query);
        return $this;
    }

    /**
     * 设置原始 body（非 JSON 场景，如 form-data、xml 等）
     * 注意：与 json() 互斥，后调用的生效
     *
     * @param mixed $body
     * @return self
     */
    public function body(mixed $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * 设置 JSON body，会自动加上 Content-Type: application/json
     * 注意：与 body() 互斥，后调用的生效
     *
     * @param array $json
     * @return self
     */
    public function json(array $json): self
    {
        $this->json = $json;
        $this->isJson = true;
        $this->isForm = false;
        return $this;
    }

    /**
     * 设置表单 body（application/x-www-form-urlencoded），Guzzle 会自动做 url 编码并设置 Content-Type
     * 注意：与 json()/body() 互斥，后调用的生效
     *
     * @param array $form 键值对数组
     * @return self
     */
    public function form(array $form): self
    {
        $this->form = $form;
        $this->isForm = true;
        $this->isJson = false;
        return $this;
    }

    /**
     * 设置超时时间（秒）
     *
     * @param float $seconds
     * @return self
     */
    public function timeout(float $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * 设置失败重试次数
     * 仅在发生 Guzzle 异常（如超时、连接失败）时重试，HTTP 4xx/5xx 不会触发重试
     *
     * @param int $times
     * @return self
     */
    public function retry(int $times): self
    {
        $this->retry = $times;
        return $this;
    }

    // ==================== 发送请求 ====================

    /**
     * 发送请求，失败时抛出异常
     *
     * @return array{status:int, headers:array, body:string, json:mixed}
     * @throws \RuntimeException 请求失败（网络异常、超时等）时抛出
     */
    public function send(): array
    {
        // 组装 Guzzle 请求选项
        $options = [
            'headers' => $this->headers,
            'timeout' => $this->timeout,
        ];

        // query 参数交给 Guzzle 自动拼接，它会正确处理 URL 编码
        if ($this->query) {
            $options['query'] = $this->query;
        }

        // body 处理：JSON / 表单 / 原始 body，以最后设置的为准
        if ($this->isJson) {
            // Guzzle 的 json 选项会自动序列化并设置 Content-Type
            $options['json'] = $this->json;
        } elseif ($this->isForm) {
            // Guzzle 的 form_params 选项会自动做 url 编码并设置表单 Content-Type
            $options['form_params'] = $this->form;
        } elseif ($this->body !== null) {
            $options['body'] = $this->body;
        }

        $attempts = 0;              // 当前尝试次数
        $lastException = null;      // 记录最后一次异常，用于最终抛出

        // 重试循环：attempts 从 1 开始，最多执行 retry + 1 次
        while ($attempts <= $this->retry) {
            $attempts++;
            try {
                // 发起同步阻塞请求
                $response = self::client()->request(
                    $this->method,
                    $this->url,
                    $options
                );

                $rawBody = (string) $response->getBody();

                // 统一返回结构，业务层无需关心 Guzzle 的 Response 对象
                return [
                    'status' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                    'body' => $rawBody,
                    'json' => json_decode($rawBody, true) ?: null,
                ];
            } catch (GuzzleException $e) {
                // 记录异常，准备重试或最终抛出
                $lastException = $e;

                // 已达到最大重试次数，跳出循环
                if ($attempts > $this->retry) {
                    break;
                }

                // 简单退避：第 1 次失败等 100ms，第 2 次等 200ms，以此类推
                usleep(100000 * $attempts);
            }
        }

        // 所有尝试均失败，抛出运行时异常
        throw new \RuntimeException(
            'HTTP 请求失败: ' . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * 发送请求，失败时返回 null 而不抛异常
     * 适合不关心失败细节、只需知道成功与否的场景
     *
     * @return array|null 成功返回统一结构，失败返回 null
     */
    public function sendQuietly(): ?array
    {
        try {
            return $this->send();
        } catch (\Throwable $e) {
            // 记录日志，便于排查问题
            \support\Log::error('HTTP 请求失败: ' . $e->getMessage());
            return null;
        }
    }
}