<?php

$root = dirname(__DIR__);

return [
  // 仅反射 Controller 与契约定义，不扫描模型、服务、启动或路由文件。
  'scan_path' => array_merge(glob($root . '/app/*/*Controller.php'), [
    $root . '/app/common/openapi',
  ]),
  'output' => $root . '/api',
  'public_operations' => [
    'POST /api/open/license/validate',
    'POST /api/open/ident/bind-ticket',
    'GET /api/open/perm/batch',
    'GET /api/open/perm/rev',
    'POST /api/open/telemetry',
    'GET /api/open/userinfo',
    'POST /api/open/ident/bind-self',
    'POST /api/oauth/token',
    'POST /api/oauth/device/init',
    'POST /api/oauth/device/token',
    'GET /oauth/authorize',
    'GET /oauth/device',
  ],
];
