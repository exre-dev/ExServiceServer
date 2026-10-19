<?php

return [
    'enable' => true,
    'jwt' => [
        /** 算法类型 HS256、HS384、HS512、RS256、RS384、RS512、ES256、ES384、ES512、PS256、PS384、PS512 */
        'algorithms' => 'HS256',

        /** access令牌秘钥（安装时自动生成64位随机值） */
        'access_secret_key' => getenv('JWT_ACCESS_SECRET') ?: 'd15c27bd1c2d5771ab0185f5699a7c4df90d944cde3ae7786fe3f8015dc452ef',

        /** access令牌过期时间，单位：秒。默认 10 小时 */
        'access_exp' => 36000,

        /** refresh令牌秘钥（安装时自动生成64位随机值） */
        'refresh_secret_key' => getenv('JWT_REFRESH_SECRET') ?: '2f9cac3d0416bb1b6e4a6b2a809f49f88dbfb2c99b46e684b286bacba117babd',

        /** refresh令牌过期时间，单位：秒。默认 7 天 */
        'refresh_exp' => 604_800,

        /** refresh 令牌是否禁用，默认不禁用 false */
        'refresh_disable' => false,

        /** 令牌签发者 */
        'iss' => 'exre.xiamolc.com',

        /** 某个时间点后才能访问，单位秒。（如：30 表示当前时间30秒后才能使用） */
        'nbf' => 0,

        /** 时钟偏差冗余时间，单位秒。建议这个余地应该不大于几分钟 */
        'leeway' => 60,

        /** 是否允许单设备登录，默认不允许 false */
        'is_single_device' => false,

        /** 缓存令牌时间，单位：秒。默认 7 天 */
        'cache_token_ttl' => 604_800,

        /** 缓存令牌前缀，默认 JWT:TOKEN: */
        'cache_token_pre' => 'JWT:TOKEN:',

        /** 缓存刷新令牌前缀，默认 JWT:REFRESH_TOKEN: */
        'cache_refresh_token_pre' => 'JWT:REFRESH_TOKEN:',

        /** 用户信息模型 */
        'user_model' => fn($uid) => \support\Db::table('app_users')
            ->where('id', $uid)
            ->select('id', 'email', 'username', 'avatar', 'platform_role', 'ban')
            ->first(),

        /** 是否支持 get 请求获取令牌 */
        'is_support_get_token' => false,
        /** GET 请求获取令牌请求key */
        'is_support_get_token_key' => 'authorization',

        /** access令牌私钥 */
        'access_private_key' => <<<EOD
            -----BEGIN RSA PRIVATE KEY-----
            ...
            -----END RSA PRIVATE KEY-----
            EOD,

        /** access令牌公钥 */
        'access_public_key' => <<<EOD
            -----BEGIN PUBLIC KEY-----
            ...
            -----END PUBLIC KEY-----
            EOD,

        /** refresh令牌私钥 */
        'refresh_private_key' => <<<EOD
            -----BEGIN RSA PRIVATE KEY-----
            ...
            -----END RSA PRIVATE KEY-----
            EOD,

        /** refresh令牌公钥 */
        'refresh_public_key' => <<<EOD
            -----BEGIN PUBLIC KEY-----
            ...
            -----END PUBLIC KEY-----
            EOD,
    ],
];
