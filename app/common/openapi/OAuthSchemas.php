<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** OAuth 授权码与设备码流程的数据结构。 */
#[OA\Schema(
  schema: 'OAuthAuthorizationCodeRequest',
  type: 'object',
  required: ['code', 'client_id', 'client_secret', 'redirect_uri'],
  properties: [
    new OA\Property(
      property: 'grant_type',
      type: 'string',
      enum: ['authorization_code'],
      default: 'authorization_code',
    ),
    new OA\Property(property: 'code', type: 'string', minLength: 1),
    new OA\Property(property: 'client_id', type: 'string', minLength: 1),
    new OA\Property(
      property: 'client_secret',
      type: 'string',
      minLength: 1,
      writeOnly: true,
    ),
    new OA\Property(
      property: 'redirect_uri',
      type: 'string',
      description: '必须与授权请求精确一致。',
    ),
    new OA\Property(
      property: 'code_verifier',
      type: 'string',
      description: '授权请求设置非空 code_challenge 时必填；支持 S256 与 plain。',
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthTokenResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['access_token', 'token_type', 'expires_in', 'scope'],
      properties: [
        new OA\Property(property: 'access_token', type: 'string'),
        new OA\Property(
          property: 'token_type',
          type: 'string',
          enum: ['Bearer'],
        ),
        new OA\Property(
          property: 'expires_in',
          type: 'integer',
          description: '会话令牌有效秒数。',
        ),
        new OA\Property(
          property: 'scope',
          type: 'string',
          description: '空格分隔的授权 scope；当前实现不签发 refresh_token。',
        ),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthDeviceInitRequest',
  type: 'object',
  required: ['client_id', 'client_secret'],
  properties: [
    new OA\Property(property: 'client_id', type: 'string', minLength: 1),
    new OA\Property(
      property: 'client_secret',
      type: 'string',
      minLength: 1,
      writeOnly: true,
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthDeviceInitResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: [
        'device_code',
        'user_code',
        'sso_request_id',
        'verification_url',
        'verification_uri_web',
        'expires_in',
        'interval',
      ],
      properties: [
        new OA\Property(
          property: 'device_code',
          type: 'string',
          pattern: '^[A-Za-z0-9_-]{43}$',
        ),
        new OA\Property(
          property: 'user_code',
          type: 'string',
          pattern: '^[0-9]{6}$',
        ),
        new OA\Property(
          property: 'sso_request_id',
          type: 'string',
          pattern: '^[A-Za-z0-9_-]{43}$',
        ),
        new OA\Property(
          property: 'verification_url',
          type: 'string',
          enum: ['/oauth/device'],
        ),
        new OA\Property(
          property: 'verification_uri_web',
          type: 'string',
          nullable: true,
          description: '配置 userweb.base_url 后为其 /login?ssoRequestId=... 地址，否则 null。',
        ),
        new OA\Property(property: 'expires_in', type: 'integer', enum: [600]),
        new OA\Property(property: 'interval', type: 'integer', enum: [5]),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthDeviceTokenRequest',
  type: 'object',
  required: ['client_id', 'client_secret', 'device_code'],
  properties: [
    new OA\Property(property: 'client_id', type: 'string', minLength: 1),
    new OA\Property(
      property: 'client_secret',
      type: 'string',
      minLength: 1,
      writeOnly: true,
    ),
    new OA\Property(property: 'device_code', type: 'string', minLength: 1),
  ],
)]
#[OA\Schema(
  schema: 'OAuthAuthorizationPending',
  type: 'object',
  required: ['error'],
  properties: [
    new OA\Property(
      property: 'error',
      type: 'string',
      enum: ['authorization_pending'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthAuthorizationInfoResponseData',
  type: 'object',
  required: ['client_id', 'name', 'type', 'scopes', 'scope_labels'],
  properties: [
    new OA\Property(property: 'client_id', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'type', type: 'string', enum: ['third_party']),
    new OA\Property(
      property: 'scopes',
      type: 'array',
      items: new OA\Items(type: 'string'),
    ),
    new OA\Property(
      property: 'scope_labels',
      type: 'array',
      items: new OA\Items(type: 'string'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthAuthorizationInfoResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/OAuthAuthorizationInfoResponseData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthAuthorizationConfirmRequest',
  type: 'object',
  required: ['client_id', 'redirect_uri', 'state'],
  properties: [
    new OA\Property(property: 'client_id', type: 'string', minLength: 1),
    new OA\Property(property: 'redirect_uri', type: 'string', minLength: 1),
    new OA\Property(property: 'state', type: 'string', minLength: 1),
    new OA\Property(
      property: 'scope',
      type: 'string',
      description: '空格分隔；与应用允许 scope 取交集，并补充 user.basic。',
    ),
    new OA\Property(property: 'code_challenge', type: 'string'),
    new OA\Property(
      property: 'code_challenge_method',
      type: 'string',
      default: 'plain',
      description: 'S256 不区分大小写，其余值按 plain 处理。',
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthAuthorizationConfirmResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['redirect'],
      properties: [
        new OA\Property(
          property: 'redirect',
          type: 'string',
          description: '回调白名单地址附加 code 与原始 state；接口本身不执行 HTTP 重定向。',
        ),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthDeviceVerifyRequest',
  type: 'object',
  required: ['user_code'],
  properties: [
    new OA\Property(
      property: 'user_code',
      type: 'string',
      minLength: 1,
      description: '初始化返回的六位用户码；当前实现只做非空与 Redis 查找。',
    ),
  ],
)]
#[OA\Schema(
  schema: 'OAuthDeviceVerifyResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['app_name'],
      properties: [new OA\Property(property: 'app_name', type: 'string')],
    ),
  ],
)]
class OAuthSchemas {}
