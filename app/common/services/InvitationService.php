<?php

namespace app\common\services;

use app\common\exception\ApiException;
use app\common\model\UserModel;
use support\Redis;

/**
 * 邀请码注册服务（用户侧网站, /api/auth/invitations/*）
 *
 * 流程: check(资格) → code(邮件 6 位码) → verify(换一次性 completionToken) → complete(建账号)。
 * 邀请码与完成令牌均存 Redis（10 分钟）, 不落表; verify 连续失败 5 次锁 10 分钟(429+Retry-After)。
 */
class InvitationService
{
    /** 验证码有效期(秒) */
    private const CODE_TTL = 600;

    /** 发送冷却(秒) */
    private const SEND_COOLDOWN = 60;

    /** verify 连续失败锁定阈值/时长 */
    private const FAIL_LIMIT = 5;
    private const LOCK_TTL = 600;

    /** completionToken 有效期(秒) */
    private const GRANT_TTL = 600;

    // ==================== 步骤 1: 资格检查 ====================

    /**
     * 未注册邮箱即符合邀请条件; 已注册 409
     * @return array{email: string, resendAvailableAt: int}
     */
    public static function check(string $email): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException("邮箱格式不正确");
        }
        if (UserModel::where('email', $email)->first()) {
            throw new ApiException("该邮箱已注册, 请直接登录", 409);
        }
        return [
            'status' => 'eligible',
            'email' => $email,
            'serverTime' => time(),
            'resendAvailableAt' => time() + self::cooldownRemaining($email),
        ];
    }

    // ==================== 步骤 2: 发送邀请码 ====================

    /**
     * @return array{status: string, serverTime: int, resendAvailableAt: int, debug_code?: string}
     */
    public static function send(string $email): array
    {
        $cdKey = "invite:cd:{$email}";
        if (Redis::exists($cdKey)) {
            throw new ApiException("发送过于频繁, 请稍后再试", 429);
        }

        $code = random_code(6, true);
        Redis::setex("invite:code:{$email}", self::CODE_TTL, $code);
        Redis::setex($cdKey, self::SEND_COOLDOWN, '1');
        Redis::del("invite:fail:{$email}");

        $sent = Email::send(
            $email,
            'EXRE 社区注册验证码',
            "你的验证码是 {$code}，10 分钟内有效。如非本人操作请忽略本邮件。"
        );

        $payload = [
            'status' => 'accepted',
            'serverTime' => time(),
            'resendAvailableAt' => time() + self::SEND_COOLDOWN,
        ];
        if (!$sent && config('app.debug')) {
            // 本地未配置 SMTP 时联调用（与 send-email-code 同策略）
            $payload['debug_code'] = $code;
        }
        return $payload;
    }

    // ==================== 步骤 3: 校验换令牌 ====================

    /**
     * @return array{status: string, completionToken: string, email: string, nickname: string, serverTime: int, completionExpiresAt: int}
     */
    public static function verify(string $email, string $code): array
    {
        $lockKey = "invite:fail:{$email}";
        $fails = (int) Redis::get($lockKey);
        if ($fails >= self::FAIL_LIMIT) {
            throw new ApiException("尝试次数过多, 已临时锁定", 429);
        }

        $cached = Redis::get("invite:code:{$email}");
        if ($cached === null || !hash_equals((string) $cached, $code)) {
            Redis::setex($lockKey, self::LOCK_TTL, (string) ($fails + 1));
            throw new ApiException("验证码无效或已过期");
        }

        // 通过: 清计数, 码一次性消费, 签发完成令牌
        Redis::del($lockKey, "invite:code:{$email}");
        $token = self::opaqueId();
        Redis::setex("invite:grant:{$token}", self::GRANT_TTL, json_encode([
            'email' => $email,
        ]));

        return [
            'status' => 'verified',
            'completionToken' => $token,
            'email' => $email,
            'nickname' => strtok($email, '@') ?: '',
            'serverTime' => time(),
            'completionExpiresAt' => time() + self::GRANT_TTL,
        ];
    }

    // ==================== 步骤 4: 完成注册 ====================

    /**
     * @return array{status: string, email: string}
     */
    public static function complete(string $completionToken, string $nickname, string $password): array
    {
        $key = "invite:grant:{$completionToken}";
        $raw = Redis::get($key);
        if ($raw === null) {
            throw new ApiException("验证已过期, 请返回邮箱重新验证", 401);
        }
        $grant = json_decode((string) $raw, true);
        $email = (string) ($grant['email'] ?? '');
        if ($email === '') {
            Redis::del($key);
            throw new ApiException("验证已过期, 请返回邮箱重新验证", 401);
        }

        $nickname = trim($nickname);
        if ($nickname === '' || mb_strlen($nickname) > 32) {
            throw new ApiException("用户名需为 1-32 个字符");
        }
        if (mb_strlen($password) < 8 || mb_strlen($password) > 64) {
            throw new ApiException("密码需为 8-64 个字符");
        }

        // 并发兜底靠 UNIQUE 索引, 这里给友好报错
        if (UserModel::where('email', $email)->first()) {
            throw new ApiException("该邮箱已注册, 请直接登录", 409);
        }

        $user = new UserModel();
        $user->email = $email;
        $user->username = $nickname;
        $user->password_hash = password_hash($password, PASSWORD_BCRYPT);
        $user->save();

        Redis::del($key); // 令牌一次性

        AuditService::log((int) $user->id, 'user', 'user.register_invitation', [
            'target_type' => 'user',
            'target_id' => $user->id,
        ]);

        return ['status' => 'registered', 'email' => $email];
    }

    // ==================== 工具 ====================

    private static function cooldownRemaining(string $email): int
    {
        $cdKey = "invite:cd:{$email}";
        if (!Redis::exists($cdKey)) {
            return 0;
        }
        return max(0, (int) Redis::ttl($cdKey));
    }

    /** 43 位不透明标识（completionToken, 契约 OpaqueIdentifier） */
    private static function opaqueId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
