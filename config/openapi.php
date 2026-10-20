<?php

return [
  // 对外发布的接口清单，与 api/public/ 中的手写契约一致。
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
