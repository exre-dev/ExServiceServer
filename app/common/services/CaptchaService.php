<?php

namespace app\common\services;

use support\Redis;

/**
 * 图片验证码服务（GD 绘制, Redis 一次性挑战）
 *
 * 挑战 5 分钟有效、答案比对一次即焚；验证码错误统一由调用方抛
 * ApiException(msg, 422)——前端用 422 与"凭据错误(400)"区分展示。
 */
class CaptchaService
{
    /** 挑战有效期(秒) */
    public const TTL = 300;

    /** 字符集（去混淆 0O1Il） */
    private const CHARSET = '23456789abcdefghjkmnpqrstuvwxyz';

    /**
     * 生成挑战
     * @return array{challengeId: string, imageBase64: string, expiresAt: int}
     */
    public static function create(): array
    {
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= self::CHARSET[random_int(0, strlen(self::CHARSET) - 1)];
        }
        $challengeId = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Redis::setex('captcha:' . $challengeId, self::TTL, strtolower($code));

        return [
            'challengeId' => $challengeId,
            'imageBase64' => self::draw($code),
            'expiresAt' => time() + self::TTL,
        ];
    }

    /**
     * 校验并消费（大小写不敏感）
     */
    public static function verify(string $challengeId, string $answer): bool
    {
        if ($challengeId === '' || $answer === '') {
            return false;
        }
        $key = 'captcha:' . $challengeId;
        $cached = Redis::get($key);
        if ($cached === null) {
            return false;
        }
        Redis::del($key); // 一次性
        return hash_equals((string) $cached, strtolower(trim($answer)));
    }

    /**
     * 校验请求中的 captcha 字段 {challengeId, answer}; 失败抛 422
     */
    public static function checkFromRequest(?array $captcha, bool $required = false): void
    {
        if ($captcha === null || !is_array($captcha)) {
            if ($required) {
                throw new \app\common\exception\ApiException("请输入图片验证码", 422);
            }
            return;
        }
        $challengeId = (string) ($captcha['challengeId'] ?? '');
        $answer = (string) ($captcha['answer'] ?? '');
        if (!self::verify($challengeId, $answer)) {
            throw new \app\common\exception\ApiException("验证码不正确或已过期", 422);
        }
    }

    /**
     * GD 绘制 150x48 PNG（随机色字符 + 干扰线 + 噪点）
     * @return string base64（无 data: 前缀, 契约 CaptchaChallenge.imageBase64）
     */
    private static function draw(string $code): string
    {
        $w = 150;
        $h = 48;
        $im = imagecreatetruecolor($w, $h);

        // 浅底
        $bg = imagecolorallocate($im, 245, 247, 250);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        // 干扰线
        for ($i = 0; $i < 4; $i++) {
            $color = imagecolorallocate($im, random_int(160, 220), random_int(160, 220), random_int(160, 220));
            imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $color);
        }

        // 字符（逐个随机色 + 垂直抖动）
        $len = strlen($code);
        $slot = (int) floor($w / ($len + 1));
        for ($i = 0; $i < $len; $i++) {
            $color = imagecolorallocate($im, random_int(20, 110), random_int(20, 110), random_int(20, 110));
            $x = $slot * ($i + 1) - random_int(6, 14);
            $y = random_int(28, 40);
            imagestring($im, 5, $x, $y - imagefontheight(5), $code[$i], $color);
            // 用内置字体画出细字符, 再叠一次偏移增强扭曲感
            imagestring($im, 5, $x + 1, $y - imagefontheight(5) - random_int(0, 2), $code[$i], $color);
        }

        // 噪点
        for ($i = 0; $i < 60; $i++) {
            $color = imagecolorallocate($im, random_int(100, 200), random_int(100, 200), random_int(100, 200));
            imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $color);
        }

        ob_start();
        imagepng($im, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return base64_encode($bytes);
    }
}
