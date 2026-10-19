<?php
return [
    'default' => 'pgsql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => getenv('DB_HOST'),
            'port' => getenv('DB_PORT'),
            'database' => getenv('DB_NAME'),
            'username' => getenv('DB_USER'),
            'password' => getenv('DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false, // Must be false for Swoole and Swow drivers.
            ],
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
        // 默认 PostgreSQL（首次部署写入 PG_*；DB_* 预留给 MySQL）
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => getenv('PG_HOST') ?: '127.0.0.1',
            'port' => getenv('PG_PORT') ?: '5432',
            'database' => getenv('PG_DB') ?: 'webman',
            'username' => getenv('PG_USER') ?: 'webman',
            'password' => getenv('PG_PASSWORD') !== false ? getenv('PG_PASSWORD') : 'webman123',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
            'pool' => [
                'max_connections' => 5,
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];
