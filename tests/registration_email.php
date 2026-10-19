<?php

/** Real registration controller/database/Redis regression; mail is recorded locally. */
namespace app\common\services {
    class Email
    {
        public static array $recipients = [];

        public static function send(string $to, string $subject, string $body, bool $isHtml = true): bool
        {
            self::$recipients[] = $to;
            return true;
        }
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    require dirname(__DIR__) . '/app/functions.php';

    use app\account\AccountController;
    use app\common\exception\ApiException;
    use app\common\services\Email;
    use support\Db;
    use support\Redis;
    use support\Request;

    Dotenv\Dotenv::createUnsafeImmutable(base_path())->safeLoad();
    support\App::loadAllConfig(['route', 'process']);

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function sendCode(string $email): array
    {
        $raw = json_encode(['email' => $email], JSON_THROW_ON_ERROR);
        $request = new Request("POST /api/auth/invitations/code HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($raw) . "\r\n\r\n" . $raw);
        try {
            $response = (new AccountController())->invitation_code($request);
        } catch (ApiException $error) {
            $response = $error->render($request);
        }
        return [$response->getStatusCode(), json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR)];
    }

    $schema = 'codex_registration_' . bin2hex(random_bytes(8));
    $prefix = $schema . ':';
    $pdo = Db::connection()->getPdo();
    $redis = Redis::connection()->client();
    $oldPrefix = $redis->getOption(\Redis::OPT_PREFIX);
    try {
        $pdo->exec('CREATE SCHEMA "' . $schema . '"');
        $pdo->exec('SET search_path TO "' . $schema . '"');
        $redis->setOption(\Redis::OPT_PREFIX, $prefix);
        preg_match('/CREATE TABLE app_users \(.*?\n\);/s', file_get_contents(base_path('database/schema.sql')), $table);
        $pdo->exec($table[0]);
        Db::table('app_users')->insert(['email' => 'existing@example.com', 'password_hash' => password_hash('test-only-password', PASSWORD_BCRYPT)]);

        [$status, $body] = sendCode('existing@example.com');
        check($status === 409 && str_contains($body['msg'], '已注册'), 'Existing email must be rejected');
        check(Email::$recipients === [], 'Existing account must not receive a registration email');
        check(Redis::keys('*') === [], 'Rejected email must not create a code or cooldown');

        Redis::setex('invite:cd:existing@example.com', 60, '1');
        [$status] = sendCode('existing@example.com');
        check($status === 409, 'Existing account must be rejected before the resend cooldown');
        Redis::del('invite:cd:existing@example.com');
        [$status] = sendCode('invalid-email');
        check($status === 400 && Email::$recipients === [], 'Invalid email must not be sent');

        [$status, $body] = sendCode('  new@example.com  ');
        check($status === 202 && $body['data']['status'] === 'accepted', 'Unused email should proceed normally');
        check(Email::$recipients === ['new@example.com'], 'Only the new email should receive a code');
        check((bool) preg_match('/^\d{6}$/', Redis::get('invite:code:new@example.com')), 'New email must have a six-digit code');
        check(Redis::ttl('invite:cd:new@example.com') > 0, 'New email cooldown missing');
        [$status] = sendCode('new@example.com');
        check($status === 429 && count(Email::$recipients) === 1, 'Cooldown must still prevent duplicate sends');

        Db::table('app_users')->insert(['email' => 'new@example.com', 'password_hash' => password_hash('test-only-password', PASSWORD_BCRYPT)]);
        [$status] = sendCode('new@example.com');
        check($status === 409 && count(Email::$recipients) === 1, 'Email registered between sends must be rejected');
        echo "PASS: existing email blocked before code/cooldown/mail; unused email accepted; resend and format checks preserved\n";
    } finally {
        foreach (Redis::keys('*') as $key) {
            check(str_starts_with($key, $prefix), 'Unexpected Redis key during cleanup');
            Redis::del(substr($key, strlen($prefix)));
        }
        $redis->setOption(\Redis::OPT_PREFIX, $oldPrefix);
        $pdo->exec('SET search_path TO public');
        check((bool) preg_match('/^codex_registration_[a-f0-9]{16}$/', $schema), 'Unsafe cleanup schema');
        $pdo->exec('DROP SCHEMA IF EXISTS "' . $schema . '" CASCADE');
    }
}
