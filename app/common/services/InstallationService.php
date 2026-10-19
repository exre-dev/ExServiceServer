<?php

namespace app\common\services;

use app\common\exception\ApiException;
use Dotenv\Dotenv;
use PDO;
use Throwable;
use Webman\Config;

/** 首次部署初始化；不依赖尚未配置的 ORM、Redis 或登录会话。 */
class InstallationService
{
    private static bool $configurationLoaded = false;

    protected static function root(): string
    {
        return base_path(false);
    }

    /** 安装标记保存在项目根目录，清理 runtime 不会重新开放安装。 */
    public static function isInstalled(): bool
    {
        clearstatcache(true, static::root() . '/install.lock');
        if (is_file(static::root() . '/install.lock')) {
            return true;
        }

        // 兼容已有部署：已有超级管理员即视为完成，绝不重复导入或重置账号。
        $environment = static::environment();
        if (empty($environment['PG_DB']) || empty($environment['PG_USER'])) {
            return false;
        }
        try {
            $pdo = static::connectDatabase([
                'host' => $environment['PG_HOST'] ?? '127.0.0.1',
                'port' => $environment['PG_PORT'] ?? '5432',
                'database' => $environment['PG_DB'],
                'username' => $environment['PG_USER'],
                'password' => $environment['PG_PASSWORD'] ?? '',
            ]);
            $installed = (bool) $pdo->query("SELECT 1 FROM public.app_users WHERE platform_role = 'superadmin' LIMIT 1")->fetchColumn();
        } catch (Throwable) {
            return false;
        }
        if ($installed && is_writable(static::root())) {
            try {
                static::writeAtomic(static::root() . '/install.lock', json_encode(['installed_at' => date('c'), 'existing' => true]));
            } catch (Throwable) {
                // 标记写入失败不应重新开放一个已经存在超级管理员的部署。
            }
        }
        return $installed;
    }

    /** 状态接口只返回环境能力，不返回数据库、Redis 密码或其他凭据。 */
    public static function status(): array
    {
        if (static::isInstalled()) {
            return ['installed' => true];
        }
        $checks = [
            ['name' => 'PHP 8.1 及以上', 'passed' => PHP_VERSION_ID >= 80100],
            ['name' => 'PDO PostgreSQL 扩展', 'passed' => extension_loaded('pdo_pgsql')],
            ['name' => 'Redis 扩展', 'passed' => extension_loaded('redis')],
            ['name' => 'GD 扩展（登录验证码）', 'passed' => extension_loaded('gd')],
            ['name' => 'Mbstring 扩展', 'passed' => extension_loaded('mbstring')],
            ['name' => '项目目录可写', 'passed' => is_writable(static::root())],
            ['name' => '.env 配置可写', 'passed' => !is_file(static::root() . '/.env') || is_writable(static::root() . '/.env')],
            ['name' => '数据库脚本可读', 'passed' => is_readable(static::root() . '/database/schema.sql')],
        ];
        return ['installed' => false, 'checks' => $checks, 'ready' => !in_array(false, array_column($checks, 'passed'), true)];
    }

    /** 验证输入并导入数据库；强制模式在同一事务内重建业务表。 */
    public static function install(array $input): void
    {
        if (static::isInstalled()) {
            throw new ApiException('系统已完成初始化，不能重复安装', 409);
        }
        if (!static::status()['ready']) {
            throw new ApiException('运行环境检查未通过，请先修复后重试');
        }
        $data = static::validate($input);

        $mutex = fopen(static::root() . '/.install.mutex', 'c');
        if (!$mutex || !flock($mutex, LOCK_EX | LOCK_NB)) {
            if ($mutex) {
                fclose($mutex);
            }
            throw new ApiException('初始化正在进行，请稍后刷新状态', 409);
        }
        $pdo = null;
        $environmentChanged = false;
        $committed = false;
        $envPath = static::root() . '/.env';
        $previous = is_file($envPath) ? file_get_contents($envPath) : null;
        try {
            if (static::isInstalled()) {
                throw new ApiException('系统已完成初始化，不能重复安装', 409);
            }
            static::checkRedis($data['redis']);
            try {
                $pdo = static::connectDatabase($data['database']);
            } catch (Throwable) {
                throw new ApiException('PostgreSQL 连接失败，请检查数据库是否已创建、连接信息及访问权限');
            }
            $pdo->beginTransaction();
            // 同一个数据库上的多个部署实例也不能同时初始化。
            if (!$pdo->query('SELECT pg_try_advisory_xact_lock(1163416133, 1)')->fetchColumn()) {
                throw new ApiException('数据库正在被其他实例初始化，请稍后重试', 409);
            }
            $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND left(tablename, 4) = 'app_' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
            if ($tables && !$data['force']) {
                throw new ApiException('数据库中已有业务表，请使用空数据库或选择强制初始化', 409);
            }
            if ($tables) {
                // 表名来自数据库并按标识符转义；一次删除全部业务表，保留其他表及外部依赖保护。
                $identifiers = array_map(fn($table) => 'public."' . str_replace('"', '""', $table) . '"', $tables);
                $pdo->exec('DROP TABLE ' . implode(', ', $identifiers));
            }
            $schema = file_get_contents(static::root() . '/database/schema.sql');
            // 统一只执行建表部分；强制重置的范围由上方明确限定。
            $schema = preg_replace('/^DROP TABLE[^\r\n]*;\s*$/m', '', $schema);
            $pdo->exec('SET LOCAL search_path TO public');
            $pdo->exec($schema);
            $statement = $pdo->prepare("INSERT INTO app_users (email, username, password_hash, platform_role, ban, created_time, updated_time) VALUES (?, ?, ?, 'superadmin', 0, ?, ?)");
            $now = date('Y-m-d H:i:s');
            $statement->execute([$data['admin']['email'], $data['admin']['username'], password_hash($data['admin']['password'], PASSWORD_BCRYPT), $now, $now]);

            $values = [
                'PG_HOST' => $data['database']['host'], 'PG_PORT' => $data['database']['port'],
                'PG_DB' => $data['database']['database'], 'PG_USER' => $data['database']['username'],
                'PG_PASSWORD' => $data['database']['password'],
                'REDIS_HOST' => $data['redis']['host'], 'REDIS_PORT' => $data['redis']['port'],
                'REDIS_DATABASE' => $data['redis']['database'], 'REDIS_PASSWORD' => $data['redis']['password'],
                'JWT_ACCESS_SECRET' => bin2hex(random_bytes(32)), 'JWT_REFRESH_SECRET' => bin2hex(random_bytes(32)),
            ];
            static::writeAtomic($envPath, static::mergeEnvironment($previous ?? '', $values));
            $environmentChanged = true;
            $pdo->commit();
            $committed = true;
            static::writeAtomic(static::root() . '/install.lock', json_encode(['installed_at' => date('c')]));
        } catch (Throwable $error) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($environmentChanged && !$committed) {
                if ($previous === null) {
                    unlink($envPath);
                } else {
                    static::writeAtomic($envPath, $previous);
                }
            }
            if ($committed) {
                // 数据库和配置已提交；下次状态检查会识别超级管理员并补写安装标记。
                return;
            }
            if ($error instanceof ApiException) {
                throw $error;
            }
            // 底层异常可能包含连接凭据或完整 SQL，不能返回给浏览器或请求日志。
            throw new ApiException('初始化失败，数据库操作已回滚；请检查数据库建表权限和配置目录写入权限后重试');
        } finally {
            flock($mutex, LOCK_UN);
            fclose($mutex);
        }
    }

    /** 每个 HTTP 进程在安装完成后的首个请求前加载新配置，无需等待重启。 */
    public static function refreshConfiguration(bool $installed = false): void
    {
        clearstatcache(true, static::root() . '/install.lock');
        if (self::$configurationLoaded || (!$installed && !is_file(static::root() . '/install.lock'))) {
            return;
        }
        if (is_file(static::root() . '/.env')) {
            Dotenv::createUnsafeMutable(static::root())->load();
        }
        Config::load(config_path(), ['route', 'process']);
        // 路由解析可能提前加载模型类；同步尚未使用的 ORM 连接定义。
        if (class_exists(\support\Db::class, false)) {
            $container = \Illuminate\Container\Container::getInstance();
            $container['config']['database.connections'] = config('database.connections');
            $container['config']['database.default'] = config('database.default');
        }
        self::$configurationLoaded = true;
    }

    protected static function connectDatabase(array $database): PDO
    {
        return new PDO("pgsql:host={$database['host']};port={$database['port']};dbname={$database['database']};connect_timeout=3;sslmode=prefer", $database['username'], $database['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected static function environment(): array
    {
        $path = static::root() . '/.env';
        try {
            $values = is_file($path) ? Dotenv::createArrayBacked(static::root())->safeLoad() : [];
            foreach (['PG_HOST', 'PG_PORT', 'PG_DB', 'PG_USER', 'PG_PASSWORD'] as $key) {
                if (!array_key_exists($key, $values) && getenv($key) !== false) {
                    $values[$key] = getenv($key);
                }
            }
            return $values;
        } catch (Throwable) {
            return [];
        }
    }

    private static function checkRedis(array $config): void
    {
        $redis = new \Redis();
        try {
            $redis->connect($config['host'], (int) $config['port'], 3);
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, 3);
            if ($config['password'] !== '') {
                $redis->auth($config['password']);
            }
            $redis->select((int) $config['database']);
            $key = 'install:check:' . bin2hex(random_bytes(16));
            $redis->setex($key, 10, 'ok');
            if ($redis->get($key) !== 'ok') {
                throw new \RuntimeException('Redis 读写校验失败');
            }
            $redis->del($key);
        } catch (Throwable) {
            throw new ApiException('Redis 连接或读写失败，请检查地址、密码、数据库编号和权限');
        } finally {
            if ($redis->isConnected()) {
                $redis->close();
            }
        }
    }

    private static function validate(array $input): array
    {
        $force = $input['force'] ?? false;
        if (!is_bool($force)) {
            throw new ApiException('强制初始化选项格式不正确');
        }
        if ($force && ($input['force_confirmation'] ?? null) !== '重置数据库') {
            throw new ApiException('强制初始化需要输入“重置数据库”确认数据清除');
        }
        foreach (['database', 'redis', 'admin'] as $group) {
            if (!isset($input[$group]) || !is_array($input[$group])) {
                throw new ApiException('初始化参数不完整');
            }
        }
        $required = ['database' => ['host', 'port', 'database', 'username', 'password'], 'redis' => ['host', 'port', 'database', 'password'], 'admin' => ['email', 'username', 'password', 'password_confirmation']];
        $data = ['force' => $force];
        foreach ($required as $group => $keys) {
            foreach ($keys as $key) {
                $value = $input[$group][$key] ?? null;
                if ((!is_string($value) && !is_int($value)) || preg_match('/[\x00-\x1f\x7f]/', (string) $value)) {
                    throw new ApiException('初始化参数格式不正确');
                }
                $data[$group][$key] = str_contains($key, 'password') ? (string) $value : trim((string) $value);
                if ($data[$group][$key] === '' && $key !== 'password') {
                    throw new ApiException('请填写完整的初始化信息');
                }
            }
        }
        foreach (['database', 'redis'] as $group) {
            if (!preg_match('/^[a-zA-Z0-9.:-]+$/D', $data[$group]['host']) || strlen($data[$group]['host']) > 253) {
                throw new ApiException('服务地址只能填写主机名或 IP 地址');
            }
            if (!ctype_digit($data[$group]['port']) || (int) $data[$group]['port'] < 1 || (int) $data[$group]['port'] > 65535) {
                throw new ApiException('端口必须是 1 到 65535 之间的整数');
            }
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,63}$/D', $data['database']['database']) || strlen($data['database']['username']) > 63) {
            throw new ApiException('数据库名称仅支持字母、数字、下划线和短横线，名称和用户名最长 63 字符');
        }
        if (!ctype_digit($data['redis']['database']) || (int) $data['redis']['database'] > 65535) {
            throw new ApiException('Redis 数据库编号必须是 0 到 65535 之间的整数');
        }
        $admin = $data['admin'];
        if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL) || strlen($admin['email']) > 255 || mb_strlen($admin['username']) > 64) {
            throw new ApiException('请输入有效的管理员邮箱及不超过 64 字符的昵称');
        }
        if (strlen($admin['password']) < 12 || strlen($admin['password']) > 72 || !preg_match('/[a-zA-Z]/', $admin['password']) || !preg_match('/[0-9]/', $admin['password'])) {
            throw new ApiException('管理员密码须为 12 到 72 字节，并包含字母和数字');
        }
        if ($admin['password'] !== $admin['password_confirmation']) {
            throw new ApiException('两次输入的管理员密码不一致');
        }
        return $data;
    }

    private static function mergeEnvironment(string $content, array $values): string
    {
        // phpdotenv 原样解析校验，避免已有损坏配置在完成后导致进程无法启动。
        try {
            Dotenv::parse($content);
        } catch (Throwable) {
            throw new ApiException('现有 .env 文件格式错误，请先修复配置文件');
        }
        foreach ($values as $key => $value) {
            $quoted = '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $value) . '"';
            $line = $key . '=' . $quoted;
            $pattern = '/^(?:export\s+)?' . preg_quote($key, '/') . '\s*=.*$/m';
            $content = preg_match($pattern, $content) ? preg_replace_callback($pattern, fn() => $line, $content) : rtrim($content) . "\n" . $line . "\n";
        }
        // 写前验证转义及变量插值，密码中包含 $、引号、反斜线也必须原样保存。
        $parsed = Dotenv::parse($content);
        foreach ($values as $key => $value) {
            if (($parsed[$key] ?? null) !== (string) $value) {
                throw new ApiException('配置值无法安全保存，请检查原有 .env 中是否存在重复或多行的同名配置');
            }
        }
        return $content;
    }

    private static function writeAtomic(string $path, string $content): void
    {
        $temporary = tempnam(dirname($path), '.install-');
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content) || !rename($temporary, $path)) {
                throw new \RuntimeException('初始化文件写入失败');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
