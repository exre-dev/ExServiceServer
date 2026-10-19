<?php

/**
 * Real PostgreSQL/Redis SSO regression test; uses a temporary schema, Redis namespace
 * and signing key. No development users, sessions or signing keys are modified.
 * php tests/sso_flow.php
 * --prepare <state.json> / --cleanup <state.json> support the browser fixture.
 * The optional PHP built-in-server router is test-only and bound to loopback.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/functions.php';

use app\common\exception\ApiException;
use app\common\middleware\CheckLogin;
use app\common\middleware\OAuthTokenAuth;
use app\common\middleware\PlatformAdmin;
use app\common\model\UserModel;
use app\common\services\OAuthService;
use app\common\services\SessionService;
use support\Db;
use support\Redis;
use support\Request;
use support\Response;

Dotenv\Dotenv::createUnsafeImmutable(base_path())->safeLoad();
support\App::loadAllConfig(['route', 'process']);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function isolate(array $state): void
{
    check((bool) preg_match('/^codex_sso_[a-f0-9]{16}$/', $state['schema']), 'Unsafe test schema');
    check($state['prefix'] === $state['schema'] . ':', 'Unsafe Redis namespace');
    // Override only this process; the application's saved configuration is untouched.
    $property = new ReflectionProperty(Webman\Config::class, 'config');
    $config = $property->getValue();
    $jwt = &$config['plugin']['tinywan']['jwt']['app']['jwt'];
    $jwt['algorithms'] = 'HS256';
    $jwt['access_secret_key'] = $state['key'];
    $jwt['refresh_secret_key'] = $state['key'];
    $jwt['is_single_device'] = false;
    $config['userweb']['base_url'] = $state['web_origin'] ?? 'http://127.0.0.1:8081';
    $property->setValue(null, $config);
    (new ReflectionProperty(Webman\Config::class, 'flatCache'))->setValue(null, []);
    Db::connection()->getPdo()->exec('SET search_path TO "' . $state['schema'] . '"');
    Redis::connection()->client()->setOption(\Redis::OPT_PREFIX, $state['prefix']);
}

function prepare(string $path): array
{
    check(!file_exists($path), 'Test state already exists');
    $schema = 'codex_sso_' . bin2hex(random_bytes(8));
    $state = ['schema' => $schema, 'prefix' => $schema . ':', 'key' => bin2hex(random_bytes(32)),
        'password' => bin2hex(random_bytes(12)), 'secret' => bin2hex(random_bytes(16)),
        'client_id' => 'sso-test-' . bin2hex(random_bytes(8)),
        'other_client_id' => 'sso-other-' . bin2hex(random_bytes(8))];
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    $pdo = Db::connection()->getPdo();
    $pdo->exec('CREATE SCHEMA "' . $schema . '"');
    isolate($state);
    $ddl = file_get_contents(base_path('database/schema.sql'));
    foreach (['app_users', 'app_server_accounts', 'app_server_members', 'app_oauth_clients',
        'app_oauth_grants', 'app_auth_sessions', 'app_audit_logs'] as $table) {
        check((bool) preg_match('/CREATE TABLE ' . $table . ' \(.*?\n\);/s', $ddl, $match), 'Missing test table ' . $table);
        $pdo->exec($match[0]);
        if ($table === 'app_oauth_clients') {
            $pdo->exec('CREATE UNIQUE INDEX uq_client_id ON app_oauth_clients (client_id)');
        }
    }
    foreach ([['member', ''], ['admin', 'superadmin'], ['banned', '']] as [$name, $role]) {
        $id = Db::table('app_users')->insertGetId(['email' => $name . '@sso-test.invalid', 'username' => 'SSO Test ' . $name,
            'platform_role' => $role, 'password_hash' => password_hash($state['password'], PASSWORD_BCRYPT),
            'ban' => $name === 'banned' ? 1 : 0]);
        $state[$name . '_id'] = (int) $id;
    }
    foreach (['client_id', 'other_client_id'] as $key) {
        Db::table('app_oauth_clients')->insert(['client_id' => $state[$key], 'name' => 'SSO Browser Test',
            'type' => 'plugin', 'client_secret_hash' => hash('sha256', $state['secret']),
            'scopes' => json_encode(['user.basic', 'user.email'])]);
    }
    foreach (['member', 'admin'] as $name) {
        $state[$name . '_token'] = SessionService::create($state[$name . '_id'])['token'];
    }
    $state['admin_user'] = UserModel::find($state['admin_id'])->toArray();
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    return $state;
}

function cleanupFixture(array $state, string $path): void
{
    isolate($state);
    // KEYS returns physical keys; DEL applies the namespace again, so strip it first.
    foreach (Redis::keys('*') as $physical) {
        check(str_starts_with($physical, $state['prefix']), 'Unexpected Redis key');
        Redis::del(substr($physical, strlen($state['prefix'])));
    }
    Db::connection()->getPdo()->exec('DROP SCHEMA IF EXISTS "' . $state['schema'] . '" CASCADE');
    unlink($path);
}

/** Dispatch the production controllers and auth middleware with a real Webman request. */
function dispatch(string $method, string $path, array $body = [], ?string $token = null): Response
{
    $raw = json_encode($body, JSON_THROW_ON_ERROR);
    $request = new Request($method . ' ' . $path . " HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/json\r\n"
        . ($token !== null ? 'Authorization: Bearer ' . $token . "\r\n" : '')
        . 'Content-Length: ' . strlen($raw) . "\r\n\r\n" . $raw);
    Webman\Context::set(Webman\Http\Request::class, $request);
    // SSO 临时 schema 已包含管理员，向管理端启动检查声明该测试夹具已初始化。
    if ($method === 'GET' && $path === '/api/installation/status') {
        return success(['installed' => true]);
    }
    $routes = [
        'GET /api/health' => [app\system\HealthController::class, 'index'],
        'POST /api/oauth/device/init' => [app\oauth\DeviceController::class, 'init'],
        'POST /api/oauth/device/token' => [app\oauth\DeviceController::class, 'token'],
        'POST /api/oauth/device/verify' => [app\oauth\DeviceController::class, 'verify'],
        'POST /api/auth/sso/inspect' => [app\oauth\SsoController::class, 'inspect'],
        'POST /api/auth/sso/login' => [app\oauth\SsoController::class, 'login'],
        'POST /api/auth/sso/confirm' => [app\oauth\SsoController::class, 'confirm'],
        'POST /api/auth/captcha/challenges' => [app\account\AccountController::class, 'captcha_challenge'],
        'GET /api/auth/me' => [app\account\AccountController::class, 'me'],
        'GET /api/server/mine' => [app\server\ServerController::class, 'mine'],
        'GET /api/open/userinfo' => [app\open\OAuthUserController::class, 'userinfo'],
        'GET /api/admin/sso-requests' => [app\admin\SsoController::class, 'index'],
    ];
    $args = [];
    $route = $routes[$method . ' ' . $path] ?? null;
    if ($method === 'POST' && preg_match('#^/api/admin/sso-requests/([A-Za-z0-9_-]{43})/revoke$#', $path, $match)) {
        $route = [app\admin\SsoController::class, 'revoke'];
        $args = [$match[1]];
    }
    if (!$route) {
        return fail('测试路由不存在', 404);
    }
    [$class, $action] = $route;
    $request->controller = $class;
    $request->action = $action;
    $next = fn ($req) => (new $class())->$action($req, ...$args);
    if (str_starts_with($path, '/api/admin/')) {
        $handler = $next;
        $next = fn ($req) => (new PlatformAdmin())->process($req, $handler);
    }
    if ($path === '/api/open/userinfo') {
        $handler = $next;
        $next = fn ($req) => (new OAuthTokenAuth())->process($req, $handler);
    }
    try {
        return (new CheckLogin())->process($request, $next);
    } catch (ApiException $error) {
        return $error->render($request);
    }
}

function payload(Response $response, int $status = 200): array
{
    check($response->getStatusCode() === $status, 'Unexpected response: ' . $response->getStatusCode() . ' ' . $response->rawBody());
    $data = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);
    return $data['data'] ?? $data;
}

$mode = $argv[1] ?? '';
$path = $argv[2] ?? __DIR__ . '/tmp/sso-' . bin2hex(random_bytes(6)) . '.json';
if (PHP_SAPI === 'cli-server') {
    $state = json_decode(file_get_contents(getenv('SSO_TEST_STATE')), true, 512, JSON_THROW_ON_ERROR);
    isolate($state);
    $url = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('#^/__test/captcha/([A-Za-z0-9_-]{43})$#', $url, $match)) {
        header('Content-Type: application/json');
        echo json_encode(['answer' => Redis::get('captcha:' . $match[1])]);
        return;
    }
    $token = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $response = dispatch($_SERVER['REQUEST_METHOD'], $url,
        json_decode(file_get_contents('php://input'), true) ?: [], $token ?: null);
    http_response_code($response->getStatusCode());
    header('Content-Type: application/json');
    echo $response->rawBody();
    return;
}
if ($mode === '--cleanup') {
    cleanupFixture(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $path);
    echo "SSO fixture cleaned\n";
    exit;
}
$state = null;
$keepFixture = false;
try {
    $state = prepare($path);
    if ($mode === '--prepare') {
        $keepFixture = true;
        echo "SSO fixture ready\n";
        return;
    }
    $credentials = ['client_id' => $state['client_id'], 'client_secret' => $state['secret']];
    $device = payload(dispatch('POST', '/api/oauth/device/init', $credentials));
    check(str_ends_with($device['verification_uri_web'], '/login?ssoRequestId=' . $device['sso_request_id']), 'Invalid frontend URL');
    $inspect = ['ssoRequestId' => $device['sso_request_id']];
    $info = payload(dispatch('POST', '/api/auth/sso/inspect', $inspect));
    check($info['expiresAt'] > $info['serverTime'] && $info['serverTime'] > 1_000_000_000_000, 'Inspect must use milliseconds');
    $poll = $credentials + ['device_code' => $device['device_code']];
    check(payload(dispatch('POST', '/api/oauth/device/token', $poll))['error'] === 'authorization_pending', 'Must wait for confirmation');
    payload(dispatch('GET', '/api/admin/sso-requests'), 401);
    check(dispatch('GET', '/api/admin/sso-requests', [], $state['member_token'])->getStatusCode() !== 200, 'Members cannot manage SSO');
    $list = payload(dispatch('GET', '/api/admin/sso-requests', [], $state['admin_token']));
    check($list['total'] === 1 && $list['list'][0]['client_name'] === 'SSO Browser Test' && $list['list'][0]['status'] === 'pending', 'Admin pending list mismatch');
    $login = $inspect + ['email' => 'member@sso-test.invalid', 'password' => $state['password']];
    payload(dispatch('POST', '/api/auth/sso/login', array_replace($login, ['password' => 'wrong'])), 400);
    payload(dispatch('POST', '/api/auth/sso/login', $login + ['captcha' => ['challengeId' => 'invalid', 'answer' => 'wrong']]), 422);
    payload(dispatch('POST', '/api/auth/sso/login', array_replace($login, ['email' => 'banned@sso-test.invalid'])), 403);
    $captcha = payload(dispatch('POST', '/api/auth/captcha/challenges'));
    $answer = Redis::get('captcha:' . $captcha['challengeId']);
    check(payload(dispatch('POST', '/api/auth/sso/login', $login + ['captcha' => ['challengeId' => $captcha['challengeId'], 'answer' => strtoupper($answer)]]))['status'] === 'completed', 'Password SSO did not complete');
    $list = payload(dispatch('GET', '/api/admin/sso-requests', [], $state['admin_token']));
    check($list['list'][0]['username'] === 'SSO Test member' && $list['list'][0]['status'] === 'authorized', 'Admin authorized list mismatch');
    check(payload(dispatch('POST', '/api/auth/sso/inspect', $inspect))['status'] === 'completed', 'Completed inspect mismatch');
    check(!OAuthService::deviceAuthorize($device['device_code'], $state['admin_id'], ['user.basic']), 'Another user must not overwrite authorization');
    payload(dispatch('POST', '/api/oauth/device/token', array_replace($poll, ['client_id' => $state['other_client_id']])), 400);
    $access = payload(dispatch('POST', '/api/oauth/device/token', $poll));
    $user = payload(dispatch('GET', '/api/open/userinfo', [], $access['access_token']));
    check($user['id'] === $state['member_id'] && $user['email'] === 'member@sso-test.invalid', 'Issued OAuth token cannot read the correct user');
    payload(dispatch('POST', '/api/oauth/device/token', $poll), 400);
    check(OAuthService::deviceByUserCode($device['user_code']) === null && OAuthService::deviceBySsoRequestId($device['sso_request_id']) === null, 'Consumed request indexes remain');

    $device = payload(dispatch('POST', '/api/oauth/device/init', $credentials));
    $inspect = ['ssoRequestId' => $device['sso_request_id']];
    payload(dispatch('POST', '/api/auth/sso/confirm', $inspect), 401);
    check(payload(dispatch('POST', '/api/auth/sso/confirm', $inspect, $state['member_token']))['status'] === 'completed', 'Existing website session cannot confirm');
    payload(dispatch('POST', '/api/admin/sso-requests/' . $device['device_code'] . '/revoke', [], $state['admin_token']));
    payload(dispatch('POST', '/api/auth/sso/inspect', $inspect), 404);
    payload(dispatch('POST', '/api/oauth/device/token', $credentials + ['device_code' => $device['device_code']]), 400);
    check(!OAuthService::deviceAuthorize($device['device_code'], $state['member_id'], ['user.basic']), 'Revoked request must not be resurrected');
    check(OAuthService::deviceByUserCode($device['user_code']) === null, 'Revoked reverse index remains');
    payload(dispatch('POST', '/api/admin/sso-requests/' . $device['device_code'] . '/revoke', [], $state['admin_token']), 404);
    check(payload(dispatch('GET', '/api/admin/sso-requests', [], $state['admin_token']))['total'] === 0, 'Admin list must be empty');
    check(Db::table('app_audit_logs')->where('action', 'oauth.device_revoke')->count() === 1, 'Revoke audit missing');
    $device = payload(dispatch('POST', '/api/oauth/device/init', $credentials));
    payload(dispatch('POST', '/api/oauth/device/verify', ['user_code' => $device['user_code']], $state['member_token']));
    payload(dispatch('POST', '/api/oauth/device/token', $credentials + ['device_code' => $device['device_code']]));
    echo "PASS: real SSO password/captcha, existing session, OAuth token/userinfo, admin list/revoke, permissions, one-time consumption and isolation\n";
} finally {
    if ($state === null && is_file($path)) {
        $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
    if ($state !== null && !$keepFixture) {
        cleanupFixture($state, $path);
    }
}
