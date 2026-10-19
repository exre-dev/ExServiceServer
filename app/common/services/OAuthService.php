<?php

namespace app\common\services;

use app\common\model\OAuthClientModel;
use app\common\model\OAuthGrantModel;
use support\Redis;

/**
 * OAuth2 服务（授权码+PKCE 流 / 设备码流）
 *
 * 授权码与设备码均为短期一次性凭证, 只存 Redis 不落库;
 * access token 为会话化 JWT（SessionService 创建, 撤销链复用会话机制）。
 */
class OAuthService
{
    public const CODE_PREFIX = 'oauth:code:';      // 授权码
    public const DEVICE_PREFIX = 'device:';        // 设备码状态
    public const DEVICE_UC_PREFIX = 'device:uc:';  // user_code → device_code
    public const DEVICE_SSO_PREFIX = 'device:sso:'; // sso_request_id → device_code（ExWebsite 登录 UI 用）

    public const CODE_TTL = 300;    // 授权码 5 分钟
    public const DEVICE_TTL = 600;  // 设备码 10 分钟

    /** scope 中文名（授权确认页展示） */
    public const SCOPE_LABELS = [
        'user.basic' => '基础资料（用户名、头像）',
        'user.email' => '邮箱地址',
        'idents.read' => '游戏标识（license/steam 等）',
        'perms.read' => '插件功能权限',
        'servers.read' => '加入的空间列表',
    ];

    /**
     * 按 client_id 加载应用
     */
    public static function client(string $clientId): ?OAuthClientModel
    {
        if ($clientId === '') {
            return null;
        }
        return OAuthClientModel::where('client_id', $clientId)->first();
    }

    /**
     * 校验 client_secret（sha256 对比）
     */
    public static function checkSecret(OAuthClientModel $client, string $secret): bool
    {
        return $secret !== '' && hash('sha256', $secret) === $client->client_secret_hash;
    }

    /**
     * scope 交集过滤（user.basic 恒包含）
     */
    public static function filterScopes(OAuthClientModel $client, array $requested): array
    {
        $allowed = is_array($client->scopes) ? $client->scopes : [];
        $scopes = array_values(array_intersect($requested, $allowed));
        if (!in_array('user.basic', $scopes, true)) {
            array_unshift($scopes, 'user.basic');
        }
        return $scopes;
    }

    /**
     * 生成并保存授权码（一次性, PKCE challenge 一并锁定）
     */
    public static function createAuthCode(
        int $userId,
        string $clientId,
        string $redirectUri,
        array $scopes,
        ?string $challenge,
        ?string $method
    ): string {
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Redis::setex(self::CODE_PREFIX . $code, self::CODE_TTL, json_encode([
            'user_id' => $userId,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'code_challenge' => $challenge,
            'code_challenge_method' => $method ?: 'plain',
        ]));
        return $code;
    }

    /**
     * 消费授权码（GETDEL 一次性）
     */
    public static function consumeAuthCode(string $code): ?array
    {
        $key = self::CODE_PREFIX . $code;
        $raw = Redis::get($key);
        if (!$raw) {
            return null;
        }
        Redis::del($key);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * PKCE 校验: S256 = base64url(sha256(verifier))
     */
    public static function pkceMatch(string $challenge, string $method, string $verifier): bool
    {
        if ($verifier === '' || $challenge === '') {
            return false;
        }
        if (strtoupper($method) === 'S256') {
            $calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            return hash_equals($challenge, $calc);
        }
        // plain
        return hash_equals($challenge, $verifier);
    }

    /**
     * upsert 用户↔应用授权关系（scope 合并取并集）
     */
    public static function upsertGrant(int $userId, string $clientId, array $scopes): OAuthGrantModel
    {
        $grant = OAuthGrantModel::where('user_id', $userId)->where('client_id', $clientId)->first();
        if (!$grant) {
            $grant = new OAuthGrantModel();
            $grant->user_id = $userId;
            $grant->client_id = $clientId;
            $grant->granted_time = date('Y-m-d H:i:s');
        }
        if ($grant->status !== 'active') {
            // 重新授权复活
            $grant->status = 'active';
            $grant->revoked_time = null;
            $grant->revoked_by = '';
            $grant->granted_time = date('Y-m-d H:i:s');
        }
        $merged = array_values(array_unique(array_merge(
            is_array($grant->scopes) ? $grant->scopes : [],
            $scopes
        )));
        $grant->scopes = $merged;
        $grant->save();
        return $grant;
    }

    /**
     * 撤销授权（并返回需要下线的会话数由调用方处理）
     */
    public static function revokeGrant(int $userId, string $clientId, string $by = 'user'): bool
    {
        $grant = OAuthGrantModel::where('user_id', $userId)->where('client_id', $clientId)->first();
        if (!$grant || $grant->status !== 'active') {
            return false;
        }
        $grant->status = 'revoked';
        $grant->revoked_time = date('Y-m-d H:i:s');
        $grant->revoked_by = $by;
        $grant->save();
        return true;
    }

    /**
     * 初始化设备码（插件设备码流）
     * @return array{device_code: string, user_code: string, sso_request_id: string}
     */
    public static function deviceInit(OAuthClientModel $client): array
    {
        $deviceCode = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        do {
            $userCode = random_code(6, true);
        } while (!Redis::set(self::DEVICE_UC_PREFIX . $userCode, $deviceCode, 'EX', self::DEVICE_TTL, 'NX'));

        // ssoRequestId（43 位不透明）: ExWebsite（user-web）登录 UI 的接收端句柄
        $ssoRequestId = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        Redis::setex(self::DEVICE_PREFIX . $deviceCode, self::DEVICE_TTL, json_encode([
            'client_id' => $client->client_id,
            'status' => 'pending',
            'user_code' => $userCode,
            'sso_request_id' => $ssoRequestId,
            'user_id' => 0,
            'scopes' => $client->scopes,
        ]));
        Redis::setex(self::DEVICE_SSO_PREFIX . $ssoRequestId, self::DEVICE_TTL, $deviceCode);

        return ['device_code' => $deviceCode, 'user_code' => $userCode, 'sso_request_id' => $ssoRequestId];
    }

    /**
     * 按 user_code 找设备码状态（浏览器验证页用）
     */
    public static function deviceByUserCode(string $userCode): ?array
    {
        $deviceCode = Redis::get(self::DEVICE_UC_PREFIX . $userCode);
        if (!$deviceCode) {
            return null;
        }
        $raw = Redis::get(self::DEVICE_PREFIX . $deviceCode);
        if (!$raw) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data + ['device_code' => $deviceCode] : null;
    }

    /**
     * 按 ssoRequestId 找设备请求（ExWebsite /auth/sso/* 三端点用）
     * @return array{device_code: string, data: array, ttl: int}|null
     */
    public static function deviceBySsoRequestId(string $ssoRequestId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $ssoRequestId)) {
            return null;
        }
        $deviceCode = Redis::get(self::DEVICE_SSO_PREFIX . $ssoRequestId);
        if (!$deviceCode) {
            return null;
        }
        $raw = Redis::get(self::DEVICE_PREFIX . $deviceCode);
        if (!$raw) {
            return null;
        }
        $data = json_decode($raw, true);
        $ttl = (int) Redis::ttl(self::DEVICE_PREFIX . $deviceCode);
        if (!is_array($data) || ($data['sso_request_id'] ?? '') !== $ssoRequestId || $ttl <= 0) {
            return null;
        }
        return [
            'device_code' => (string) $deviceCode,
            'data' => $data,
            'ttl' => $ttl,
        ];
    }

    /**
     * 用户在验证页确认授权
     */
    public static function deviceAuthorize(string $deviceCode, int $userId, array $scopes): bool
    {
        // 状态检查与写入原子执行，不能覆盖其他用户的确认或复活已撤销的请求。
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
local ttl = redis.call('PTTL', KEYS[1])
if not raw or ttl <= 0 then return 0 end
local ok, data = pcall(cjson.decode, raw)
if not ok or type(data) ~= 'table' then return 0 end
if data.status == 'authorized' then
    return tonumber(data.user_id) == tonumber(ARGV[1]) and 1 or 0
end
if data.status ~= 'pending' then return 0 end
data.status = 'authorized'
data.user_id = tonumber(ARGV[1])
data.scopes = cjson.decode(ARGV[2])
redis.call('SET', KEYS[1], cjson.encode(data), 'PX', ttl)
return 1
LUA;
        return (int) Redis::eval($script, 1, self::DEVICE_PREFIX . $deviceCode, $userId, json_encode(array_values($scopes))) === 1;
    }

    /**
     * 插件轮询消费设备码
     * @return array{status: string, data?: array}
     */
    public static function deviceConsume(string $deviceCode, string $clientId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $deviceCode)) {
            return ['status' => 'expired_token'];
        }
        // 先核对所属应用，再一次性消费；重复轮询不能签发第二个会话。
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return cjson.encode({status = 'expired_token'}) end
local ok, data = pcall(cjson.decode, raw)
if not ok or type(data) ~= 'table' then return cjson.encode({status = 'expired_token'}) end
if data.client_id ~= ARGV[2] then return cjson.encode({status = 'client_mismatch'}) end
if data.status ~= 'authorized' then return cjson.encode({status = 'authorization_pending'}) end
local prefix = string.sub(KEYS[1], 1, #KEYS[1] - #ARGV[1])
redis.call('DEL', KEYS[1])
for _, suffix in ipairs({'uc:' .. (data.user_code or ''), 'sso:' .. (data.sso_request_id or '')}) do
    local key = prefix .. suffix
    if redis.call('GET', key) == ARGV[1] then redis.call('DEL', key) end
end
return cjson.encode({status = 'ok', data = data})
LUA;
        return json_decode((string) Redis::eval($script, 1, self::DEVICE_PREFIX . $deviceCode, $deviceCode, $clientId), true);
    }

    /** 撤销设备请求并清除两个反向索引，避免与确认、消费并发时残留或复活。 */
    public static function deviceRevoke(string $deviceCode): bool
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $deviceCode)) {
            return false;
        }
        $script = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
if not raw then return 0 end
local prefix = string.sub(KEYS[1], 1, #KEYS[1] - #ARGV[1])
redis.call('DEL', KEYS[1])
local ok, data = pcall(cjson.decode, raw)
if ok and type(data) == 'table' then
    for _, suffix in ipairs({'uc:' .. (data.user_code or ''), 'sso:' .. (data.sso_request_id or '')}) do
        local key = prefix .. suffix
        if redis.call('GET', key) == ARGV[1] then redis.call('DEL', key) end
    end
end
return 1
LUA;
        return (int) Redis::eval($script, 1, self::DEVICE_PREFIX . $deviceCode, $deviceCode) === 1;
    }
}
