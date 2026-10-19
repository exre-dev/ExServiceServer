<?php

/** 初始化回归：使用独立临时数据库和目录，不修改当前部署的配置、用户或数据。 */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/functions.php';

use app\common\exception\ApiException;
use app\common\services\InstallationService;
use app\system\InstallationController;
use Dotenv\Dotenv;
use support\Request;

class IsolatedInstallation extends InstallationService
{
    public static string $directory;

    protected static function root(): string
    {
        return self::$directory;
    }

    protected static function environment(): array
    {
        return is_file(self::$directory . '/.env') ? Dotenv::createArrayBacked(self::$directory)->safeLoad() : [];
    }
}

function checkInstallation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejectInstallation(callable $action, string $message, int $code = 400): void
{
    try {
        $action();
    } catch (ApiException $error) {
        checkInstallation($error->getCode() === $code && str_contains($error->getMessage(), $message), 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

Dotenv::createUnsafeImmutable(base_path())->safeLoad();
support\App::loadAllConfig(['route', 'process']);
// 模拟路由解析提前加载了 ORM、但初始化门禁尚未允许业务查询的常驻进程。
class_exists(support\Db::class);
$connection = require base_path('config/database.php');
$pg = $connection['connections']['pgsql'];
$source = new PDO("pgsql:host={$pg['host']};port={$pg['port']};dbname={$pg['database']}", $pg['username'], $pg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'codex_install_' . bin2hex(random_bytes(8));
$directory = base_path('tests/tmp/' . $name);
IsolatedInstallation::$directory = $directory;
mkdir($directory . '/database', 0755, true);
$schema = file_get_contents(base_path('database/schema.sql'));
file_put_contents($directory . '/database/schema.sql', $schema);
$originalEnvironment = "# preserve unrelated settings\nMAIL_HOST=mail.example.com\n";
file_put_contents($directory . '/.env', $originalEnvironment);
$databaseCreated = false;
$pdo = null;
$data = [
    'database' => ['host' => $pg['host'], 'port' => $pg['port'], 'database' => $name, 'username' => $pg['username'], 'password' => $pg['password']],
    'redis' => ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: 6379, 'database' => getenv('REDIS_DATABASE') ?: 0, 'password' => getenv('REDIS_PASSWORD') ?: ''],
    'admin' => ['email' => 'owner@installation.invalid', 'username' => 'Deployment Owner', 'password' => 'Install-test-123456', 'password_confirmation' => 'Install-test-123456'],
];
try {
    $source->exec('CREATE DATABASE "' . $name . '"');
    $databaseCreated = true;
    $pdo = new PDO("pgsql:host={$pg['host']};port={$pg['port']};dbname=$name", $pg['username'], $pg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $status = IsolatedInstallation::status();
    checkInstallation(!$status['installed'] && $status['ready'], 'Fresh deployment must expose ready checks');
    checkInstallation(!str_contains(json_encode($status), $pg['password']), 'Status must not expose credentials');

    $invalid = $data;
    $invalid['database']['host'] = 'localhost;dbname=another';
    rejectInstallation(fn() => IsolatedInstallation::install($invalid), '服务地址');
    $invalid = $data;
    $invalid['admin']['password_confirmation'] = 'different';
    rejectInstallation(fn() => IsolatedInstallation::install($invalid), '不一致');
    $invalid = $data;
    $invalid['force'] = 'true';
    rejectInstallation(fn() => IsolatedInstallation::install($invalid), '选项格式');
    $forceData = $data;
    $forceData['force'] = true;
    rejectInstallation(fn() => IsolatedInstallation::install($forceData), '确认数据清除');
    $forceData['force_confirmation'] = '重置数据库';

    $mutex = fopen($directory . '/.install.mutex', 'c');
    flock($mutex, LOCK_EX);
    rejectInstallation(fn() => IsolatedInstallation::install($data), '正在进行', 409);
    flock($mutex, LOCK_UN);
    fclose($mutex);

    $controller = new InstallationController();
    $makeRequest = function (string $origin, bool $header = true) use ($data): Request {
        $body = json_encode($data);
        return new Request("POST /api/installation/install HTTP/1.1\r\nHost: localhost\r\nOrigin: $origin\r\n" . ($header ? "X-Setup-Request: 1\r\n" : '') . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    };
    rejectInstallation(fn() => $controller->install($makeRequest('https://untrusted.invalid'), new IsolatedInstallation()), '当前部署', 403);
    rejectInstallation(fn() => $controller->install($makeRequest('http://localhost', false), new IsolatedInstallation()), '当前部署', 403);

    $invalid = $data;
    $invalid['redis']['port'] = 1;
    rejectInstallation(fn() => IsolatedInstallation::install($invalid), 'Redis');
    checkInstallation(file_get_contents($directory . '/.env') === $originalEnvironment, 'Failed Redis check must not change config');

    $pdo->beginTransaction();
    $pdo->query('SELECT pg_advisory_xact_lock(1163416133, 1)');
    rejectInstallation(fn() => IsolatedInstallation::install($data), '其他实例', 409);
    $pdo->rollBack();

    $pdo->exec('CREATE TABLE app_existing (id int)');
    rejectInstallation(fn() => IsolatedInstallation::install($data), '已有业务表', 409);
    checkInstallation($pdo->query("SELECT to_regclass('public.app_existing')")->fetchColumn() === 'app_existing', 'Existing table must survive');
    $pdo->exec('DROP TABLE app_existing');

    file_put_contents($directory . '/database/schema.sql', $schema . "\nINVALID_SQL_FOR_ROLLBACK;\n");
    rejectInstallation(fn() => IsolatedInstallation::install($data), '已回滚');
    checkInstallation((int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn() === 0, 'DDL must roll back completely');
    checkInstallation(file_get_contents($directory . '/.env') === $originalEnvironment && !is_file($directory . '/install.lock'), 'Failed import must leave deployment retryable');
    file_put_contents($directory . '/database/schema.sql', $schema);

    $merge = new ReflectionMethod(InstallationService::class, 'mergeEnvironment');
    $special = 'spaces # single\' double" backslash\\ dollar${UNRESOLVED}';
    $merged = $merge->invoke(null, $originalEnvironment, ['PG_PASSWORD' => $special, 'REDIS_PASSWORD' => '0']);
    $parsed = Dotenv::parse($merged);
    checkInstallation($parsed['PG_PASSWORD'] === $special && $parsed['REDIS_PASSWORD'] === '0' && $parsed['MAIL_HOST'] === 'mail.example.com', 'Environment escaping must round trip without losing unrelated settings');

    $response = $controller->install($makeRequest('http://localhost'), new IsolatedInstallation());
    checkInstallation($response->getStatusCode() === 200 && IsolatedInstallation::isInstalled(), 'Installation should succeed');
    $user = $pdo->query('SELECT * FROM app_users')->fetchAll(PDO::FETCH_ASSOC);
    checkInstallation(count($user) === 1 && $user[0]['platform_role'] === 'superadmin' && password_verify($data['admin']['password'], $user[0]['password_hash']), 'Only the configured admin should exist');
    checkInstallation((int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn() === preg_match_all('/^CREATE TABLE /m', $schema), 'All schema tables must be imported');
    $saved = Dotenv::createArrayBacked($directory)->load();
    checkInstallation($saved['MAIL_HOST'] === 'mail.example.com' && $saved['PG_DB'] === $name && strlen($saved['JWT_ACCESS_SECRET']) === 64 && $saved['JWT_ACCESS_SECRET'] !== $saved['JWT_REFRESH_SECRET'], 'Config and independent signing keys must persist');
    checkInstallation(!str_contains(file_get_contents($directory . '/.env'), $data['admin']['password']), 'Admin password must not be saved in environment');
    rejectInstallation(fn() => IsolatedInstallation::install($data), '已完成', 409);
    rejectInstallation(fn() => IsolatedInstallation::install($forceData), '已完成', 409);
    IsolatedInstallation::refreshConfiguration();
    checkInstallation(config('database.connections.pgsql.database') === $name, 'Worker must load the saved database configuration');
    checkInstallation(config('plugin.tinywan.jwt.app.jwt.access_secret_key') === $saved['JWT_ACCESS_SECRET'], 'Worker must load new signing keys');
    checkInstallation(support\Db::connection()->getPdo()->query('SELECT current_database()')->fetchColumn() === $name, 'Preloaded ORM must use the newly installed database');
    checkInstallation((int) support\Db::table('app_users')->count() === 1, 'Admin must be available to the ORM without restarting');

    // 模拟另一份尚未初始化的部署连接已有数据库，强制重建时必须保护其他表并支持回滚。
    unlink($directory . '/install.lock');
    file_put_contents($directory . '/.env', $originalEnvironment);
    $pdo->exec('CREATE TABLE app_legacy (id int PRIMARY KEY)');
    $pdo->exec('INSERT INTO app_legacy VALUES (123)');
    $pdo->exec('CREATE TABLE unrelated_records (id int PRIMARY KEY)');
    $pdo->exec('INSERT INTO unrelated_records VALUES (456)');
    file_put_contents($directory . '/database/schema.sql', $schema . "\nINVALID_SQL_FOR_ROLLBACK;\n");
    rejectInstallation(fn() => IsolatedInstallation::install($forceData), '已回滚');
    checkInstallation((int) $pdo->query('SELECT id FROM app_legacy')->fetchColumn() === 123, 'Failed force import must restore dropped tables and data');
    checkInstallation($pdo->query('SELECT email FROM app_users')->fetchColumn() === $data['admin']['email'], 'Failed force import must preserve the original admin');
    checkInstallation(file_get_contents($directory . '/.env') === $originalEnvironment && !is_file($directory . '/install.lock'), 'Failed force import must preserve config and allow retry');
    file_put_contents($directory . '/database/schema.sql', $schema);
    $pdo->exec('ALTER TABLE unrelated_records ADD COLUMN user_id integer REFERENCES app_users(id)');
    rejectInstallation(fn() => IsolatedInstallation::install($forceData), '已回滚');
    checkInstallation((int) $pdo->query('SELECT id FROM app_legacy')->fetchColumn() === 123, 'External dependency rejection must preserve all tables');
    $pdo->exec('ALTER TABLE unrelated_records DROP COLUMN user_id');
    $forceData['admin']['email'] = 'reset-owner@installation.invalid';
    IsolatedInstallation::install($forceData);
    checkInstallation(!$pdo->query("SELECT to_regclass('public.app_legacy')")->fetchColumn(), 'Force mode must remove old app tables');
    checkInstallation((int) $pdo->query('SELECT id FROM unrelated_records')->fetchColumn() === 456, 'Force mode must preserve unrelated tables and data');
    $users = $pdo->query('SELECT * FROM app_users')->fetchAll(PDO::FETCH_ASSOC);
    checkInstallation(count($users) === 1 && $users[0]['email'] === $forceData['admin']['email'] && password_verify($forceData['admin']['password'], $users[0]['password_hash']), 'Force mode must write only the new administrator');
    checkInstallation((int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND left(tablename, 4) = 'app_'")->fetchColumn() === preg_match_all('/^CREATE TABLE /m', $schema), 'Force mode must recreate the complete schema including feedback tables');
    $resetEnvironment = Dotenv::createArrayBacked($directory)->load();
    checkInstallation($resetEnvironment['JWT_ACCESS_SECRET'] !== $saved['JWT_ACCESS_SECRET'], 'Force mode must generate new signing keys');
    unlink($directory . '/install.lock');
    checkInstallation(IsolatedInstallation::isInstalled() && is_file($directory . '/install.lock'), 'Existing deployment must be recognized and relocked without reimport');
    echo "PASS: standard installation, forced reset confirmation, forced rollback, external dependency protection, unrelated data preservation, complete schema recreation, new administrator, installation lock and legacy recognition\n";
} finally {
    $pdo = null;
    if ($databaseCreated) {
        checkInstallation((bool) preg_match('/^codex_install_[a-f0-9]{16}$/D', $name), 'Unsafe database cleanup name');
        $source->exec('DROP DATABASE "' . $name . '" WITH (FORCE)');
    }
    foreach (['.env', 'install.lock', '.install.mutex', 'database/schema.sql'] as $file) {
        if (is_file($directory . '/' . $file)) {
            unlink($directory . '/' . $file);
        }
    }
    rmdir($directory . '/database');
    rmdir($directory);
}
