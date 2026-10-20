<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

#[OA\Info(
  title: 'EXRE 社区 API',
  version: '1.0.0',
  description: '由 PHP 接口元数据生成；服务器地址相对于当前部署根路径。',
)]
#[OA\Server(url: '/')]
#[OA\SecurityScheme(
  securityScheme: 'sessionAuth',
  type: 'http',
  scheme: 'bearer',
  description: '会话 JWT，保留会话撤销和用户状态校验。',
)]
#[OA\SecurityScheme(
  securityScheme: 'oauthBearer',
  type: 'http',
  scheme: 'bearer',
  description: 'OAuth 访问令牌。',
)]
#[OA\SecurityScheme(
  securityScheme: 'bindCodeAuth',
  type: 'apiKey',
  name: 'X-Bind-Code',
  in: 'header',
  description: '插件绑定码。',
)]
#[OA\Schema(
  schema: 'BusinessErrorBody',
  type: 'object',
  required: ['msg'],
  properties: [
    new OA\Property(
      property: 'msg',
      type: 'string',
      description: '中文业务错误消息。',
    ),
  ],
)]
#[OA\Response(
  response: 'BusinessError',
  description: '业务错误；HTTP 状态码由接口具体分支决定。',
  content: new OA\JsonContent(ref: '#/components/schemas/BusinessErrorBody'),
)]
#[OA\Response(
  response: 'SetupRequired',
  description: '系统尚未初始化。',
  headers: [
    new OA\Header(
      header: 'X-Setup-Required',
      description: '值为 1，提示先完成初始化。',
      schema: new OA\Schema(type: 'string', enum: ['1']),
    ),
  ],
  content: new OA\JsonContent(ref: '#/components/schemas/BusinessErrorBody'),
)]
final class Document {}
