<?php

namespace app\common\services;

use app\common\model\AuthSessionModel;
use support\Redis;
use Tinywan\Jwt\JwtToken;

/**
 * 登录会话服务（多设备/多平台 token 管理）
 *
 * 机制：登录时创建 app_auth_sessions 记录，会话 id 作为 sid claim 写入 JWT；
 * CheckLogin 校验时必须命中 Redis sess:{sid} 且 active——撤销 = 删 Redis + 改表，
 * 下一次请求立即 401，无需等 JWT 自然过期。
 */
class SessionService
{
    /** Redis 会话键 */
    private const REDIS_PREFIX = 'sess:';

    /** last_used_time 落库节流间隔（秒） */
    private const TOUCH_INTERVAL = 300;

    /**
     * 创建会话并签发 JWT
     *
     * @param int $userId 用户 id
     * @param array $opts 可选: login_type(password/oauth) client_id platform scope ip ua
     * @return array{token: string, sid: int, expires_in: int}
     */
    public static function create(int $userId, array $opts = []): array
    {
        $loginType = $opts['login_type'] ?? 'password';
        $platform = $opts['platform'] ?? 'web';
        $ip = $opts['ip'] ?? '';
        $ua = $opts['ua'] ?? '';

        $expiresIn = (int) config('plugin.tinywan.jwt.app.jwt.access_exp', 36000);
        $now = date('Y-m-d H:i:s');

        $session = new AuthSessionModel();
        $session->user_id = $userId;
        $session->login_type = $loginType;
        $session->client_id = $opts['client_id'] ?? null;
        $session->device_name = $opts['device_name'] ?? self::deviceNameFromUa($ua);
        $session->platform = $platform;
        $session->ip = $ip;
        $session->ua_hash = $ua ? hash('sha256', $ua) : '';
        $session->scope = $opts['scope'] ?? '';
        $session->status = 'active';
        $session->created_time = $now;
        $session->last_used_time = $now;
        $session->expires_time = date('Y-m-d H:i:s', time() + $expiresIn);
        $session->save();

        $sid = (int) $session->id;

        // Redis 会话快照（CheckLogin 的快速校验路径）
        Redis::setex(self::REDIS_PREFIX . $sid, $expiresIn, json_encode([
            'user_id' => $userId,
            'platform' => $platform,
            'client_id' => $opts['client_id'] ?? null,
            'scope' => $opts['scope'] ?? '',
            'status' => 'active',
        ]));

        // JWT 携带 sid；extend 其余字段沿用项目既有约定（id + client）
        $token = JwtToken::generateToken([
            'id' => $userId,
            'sid' => $sid,
            'client' => JwtToken::TOKEN_CLIENT_WEB,
        ]);

        return [
            'token' => $token['access_token'],
            'sid' => $sid,
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * 校验会话是否有效（Redis 优先, 未命中回源数据库并重建缓存）
     * 有效时顺带节流更新 last_used_time
     *
     * @return array|null 会话快照,无效返回 null
     */
    public static function check(int $sid): ?array
    {
        $key = self::REDIS_PREFIX . $sid;

        $raw = Redis::get($key);
        if ($raw !== null) {
            $snap = json_decode($raw, true);
            if (!is_array($snap) || ($snap['status'] ?? '') !== 'active') {
                return null;
            }
            self::touch($sid);
            return $snap;
        }

        // 回源: 数据库中 active 且未过期
        $session = AuthSessionModel::find($sid);
        if (!$session
            || $session->status !== 'active'
            || ($session->expires_time && strtotime((string) $session->expires_time) < time())) {
            return null;
        }

        $snap = [
            'user_id' => (int) $session->user_id,
            'platform' => (string) $session->platform,
            'client_id' => $session->client_id,
            'scope' => (string) $session->scope,
            'status' => 'active',
        ];
        $ttl = $session->expires_time ? max(1, strtotime((string) $session->expires_time) - time()) : 3600;
        Redis::setex($key, $ttl, json_encode($snap));
        self::touch($sid);
        return $snap;
    }

    /**
     * 撤销单个会话（登出 / 用户撤销某设备）
     */
    public static function revoke(int $sid): bool
    {
        Redis::del(self::REDIS_PREFIX . $sid);

        $session = AuthSessionModel::find($sid);
        if ($session && $session->status === 'active') {
            $session->status = 'revoked';
            $session->updated_time = date('Y-m-d H:i:s');
            $session->save();
            return true;
        }
        return false;
    }

    /**
     * 撤销用户全部会话（改密 / 平台封禁 / 应用授权撤销时调用）
     * @return int 撤销条数
     */
    public static function revokeAllForUser(int $userId, ?int $exceptSid = null): int
    {
        $rows = AuthSessionModel::where('user_id', $userId)
            ->where('status', 'active')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            if ($exceptSid !== null && (int) $row->id === $exceptSid) {
                continue;
            }
            Redis::del(self::REDIS_PREFIX . $row->id);
            $row->status = 'revoked';
            $row->updated_time = date('Y-m-d H:i:s');
            $row->save();
            $count++;
        }
        return $count;
    }

    /**
     * 撤销某应用名下全部会话（应用停用/密钥重置时调用）
     * @return int 撤销条数
     */
    public static function revokeAllForClient(string $clientId): int
    {
        $rows = AuthSessionModel::where('client_id', $clientId)
            ->where('status', 'active')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            Redis::del(self::REDIS_PREFIX . $row->id);
            $row->status = 'revoked';
            $row->updated_time = date('Y-m-d H:i:s');
            $row->save();
            $count++;
        }
        return $count;
    }

    /**
     * 撤销某用户在某应用名下的全部会话（用户撤销授权时调用）
     * @return int 撤销条数
     */
    public static function revokeAllForUserClient(int $userId, string $clientId): int
    {
        $rows = AuthSessionModel::where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('status', 'active')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            Redis::del(self::REDIS_PREFIX . $row->id);
            $row->status = 'revoked';
            $row->updated_time = date('Y-m-d H:i:s');
            $row->save();
            $count++;
        }
        return $count;
    }

    /**
     * 节流更新 last_used_time（5 分钟内不重复落库）
     */
    private static function touch(int $sid): void
    {
        $markKey = self::REDIS_PREFIX . $sid . ':touched';
        if (Redis::exists($markKey)) {
            return;
        }
        Redis::setex($markKey, self::TOUCH_INTERVAL, '1');
        AuthSessionModel::where('id', $sid)->update([
            'last_used_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 从 UA 识别设备名（粗粒度, 管理端展示够用）
     */
    public static function deviceNameFromUa(string $ua): string
    {
        if ($ua === '') {
            return '未知设备';
        }
        $browser = '浏览器';
        if (preg_match('/Edg\//', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\//', $ua) && !preg_match('/Chromium/', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\//', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\//', $ua) && !preg_match('/Chrome/', $ua)) {
            $browser = 'Safari';
        }

        $os = '未知系统';
        if (stripos($ua, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (stripos($ua, 'Mac OS') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        } elseif (stripos($ua, 'Android') !== false) {
            $os = 'Android';
        } elseif (stripos($ua, 'iPhone|iPad') !== false) {
            $os = 'iOS';
        }
        return $browser . ' / ' . $os;
    }
}
