<?php

namespace app\common\services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * 邮件
 */
class Email
{
    /**
     * 快速发送邮件
     * @param string $to 收件人邮箱
     * @param string $subject 邮件主题
     * @param string $body 邮件内容
     * @param bool $isHtml 是否为 HTML 邮件
     * @return bool 发送成功返回 true，失败返回 false
     */
    public static function send(string $to, string $subject, string $body, bool $isHtml = true): bool
    {
        // 读取插件配置，前缀 plugin.email
        $config = config('email');

        $mail = new PHPMailer(true); // true 表示启用异常
        try {
            // --- 基础 SMTP 配置 ---
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->Port = (int) $config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];

            // 加密方式映射
            $mail->SMTPSecure = $config['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            $mail->Timeout = $config['timeout'] ?? 15;
            $mail->SMTPDebug = $config['debug'] ?? 0;
            $mail->CharSet = 'UTF-8';

            // --- 收发件人 ---
            $mail->setFrom($config['from']['address'], $config['from']['name']);
            $mail->addAddress($to);

            // --- 内容 ---
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;

            return $mail->send();
        } catch (Exception $e) {
            // 生产环境务必记录日志，而不是直接输出
            \support\Log::error("Mail send failed: {$mail->ErrorInfo}");
            return false;
        }
    }
}