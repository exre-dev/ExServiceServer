<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 用户网站 SSO 接收端的数据结构。 */
#[OA\Schema(
  schema: 'SsoRequest',
  type: 'object',
  required: ['ssoRequestId'],
  properties: [
    new OA\Property(
      property: 'ssoRequestId',
      type: 'string',
      minLength: 43,
      maxLength: 43,
      pattern: '^[A-Za-z0-9_-]{43}$',
      description: '由设备码初始化接口返回的 sso_request_id。',
    ),
  ],
)]
#[OA\Schema(
  schema: 'SsoCaptcha',
  type: 'object',
  required: ['challengeId', 'answer'],
  properties: [
    new OA\Property(property: 'challengeId', type: 'string', minLength: 1),
    new OA\Property(property: 'answer', type: 'string', minLength: 1),
  ],
)]
#[OA\Schema(
  schema: 'SsoLoginRequest',
  type: 'object',
  required: ['ssoRequestId', 'email', 'password'],
  properties: [
    new OA\Property(
      property: 'ssoRequestId',
      type: 'string',
      minLength: 43,
      maxLength: 43,
      pattern: '^[A-Za-z0-9_-]{43}$',
      description: '由设备码初始化接口返回的 sso_request_id。',
    ),
    new OA\Property(
      property: 'email',
      type: 'string',
      description: '登录邮箱，去除两端空白。',
    ),
    new OA\Property(property: 'password', type: 'string', writeOnly: true),
    new OA\Property(
      property: 'captcha',
      ref: '#/components/schemas/SsoCaptcha',
      description: '可省略；传入时会消费并校验挑战，失败返回 422。',
    ),
  ],
)]
#[OA\Schema(
  schema: 'SsoInspectResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['ssoRequestId', 'status', 'expiresAt', 'serverTime'],
      properties: [
        new OA\Property(
          property: 'ssoRequestId',
          type: 'string',
          minLength: 43,
          maxLength: 43,
          pattern: '^[A-Za-z0-9_-]{43}$',
          description: '由设备码初始化接口返回的 sso_request_id。',
        ),
        new OA\Property(
          property: 'status',
          type: 'string',
          enum: ['pending', 'completed'],
        ),
        new OA\Property(
          property: 'expiresAt',
          type: 'integer',
          format: 'int64',
          description: 'Unix 毫秒时间。',
        ),
        new OA\Property(
          property: 'serverTime',
          type: 'integer',
          format: 'int64',
          description: 'Unix 毫秒时间。',
        ),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'SsoCompletedResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['status', 'ssoRequestId'],
      properties: [
        new OA\Property(
          property: 'status',
          type: 'string',
          enum: ['completed'],
        ),
        new OA\Property(
          property: 'ssoRequestId',
          type: 'string',
          minLength: 43,
          maxLength: 43,
          pattern: '^[A-Za-z0-9_-]{43}$',
          description: '由设备码初始化接口返回的 sso_request_id。',
        ),
      ],
    ),
  ],
)]
class SsoSchemas {}
