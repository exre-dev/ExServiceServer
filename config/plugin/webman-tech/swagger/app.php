<?php

return [
  // 仅使用离线生成器，不提供插件 HTTP 文档入口或自动业务路由。
  'enable' => true,
  'global_route' => [
    'enable' => false,
    'register_route' => false,
  ],
  'openapi_doc' => [
    'expand_eloquent_model_enable' => false,
    'auto_load_schema_classes' => [],
    'enable_dto_generator' => false,
  ],
];
