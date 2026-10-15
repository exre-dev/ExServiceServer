<?php

return [
    // 驱动，这里固定为 smtp
    'driver' => 'smtp',

    // SMTP 服务器地址，例如 smtp.qq.com 或 smtp.163.com
    'host' => getenv('MAIL_HOST'),

    // 端口：587 通常对应 STARTTLS，465 通常对应 SMTPS
    'port' => 587,

    // 加密方式：'tls' 或 'ssl'，分别对应 PHPMailer 的 ENCRYPTION_STARTTLS 和 ENCRYPTION_SMTPS
    'encryption' => 'tls',

    // 认证信息
    'username' => getenv('MAIL_USERNAME'),
    // QQ/163 等邮箱此处必须填“授权码”，不是登录密码
    'password' => getenv('MAIL_PASSWORD'),

    // 发件人信息：发件邮箱通常必须与 username 一致
    'from' => [
        'address' => getenv('MAIL_USERNAME'),
        'name' => getenv('MAIL_NICKNAME'),
    ],

    // 超时时间（秒），建议设置，避免 SMTP 卡住业务进程
    'timeout' => 15,

    // 调试级别，生产环境建议设为 0 关闭
    'debug' => 0,
];