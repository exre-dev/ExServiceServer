<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 实际 PHP 请求与响应结构。 */
#[OA\Schema(
  schema: 'AccountCaptchaAnswer',
  type: 'object',
  required: ['challengeId', 'answer'],
  properties: [
    new OA\Property(property: 'challengeId', type: 'string'),
    new OA\Property(property: 'answer', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'AccountLoginRequest',
  type: 'object',
  required: ['email', 'password'],
  properties: [
    new OA\Property(property: 'email', type: 'string', format: 'email'),
    new OA\Property(property: 'password', type: 'string', format: 'password'),
    new OA\Property(
      property: 'captcha',
      ref: '#/components/schemas/AccountCaptchaAnswer',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountAdminLoginRequest',
  type: 'object',
  required: ['email', 'password', 'captcha'],
  properties: [
    new OA\Property(property: 'email', type: 'string', format: 'email'),
    new OA\Property(property: 'password', type: 'string', format: 'password'),
    new OA\Property(
      property: 'captcha',
      ref: '#/components/schemas/AccountCaptchaAnswer',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountRegisterRequest',
  type: 'object',
  required: ['email', 'password'],
  properties: [
    new OA\Property(property: 'email', type: 'string', format: 'email'),
    new OA\Property(
      property: 'password',
      type: 'string',
      format: 'password',
      minLength: 6,
    ),
    new OA\Property(property: 'username', type: 'string'),
    new OA\Property(property: 'qq', type: 'string'),
    new OA\Property(property: 'code', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'AccountEmailRequest',
  type: 'object',
  required: ['email'],
  properties: [
    new OA\Property(property: 'email', type: 'string', format: 'email'),
  ],
)]
#[OA\Schema(
  schema: 'AccountProfileRequest',
  type: 'object',
  required: [],
  properties: [
    new OA\Property(
      property: 'nickname',
      type: 'string',
      minLength: 1,
      maxLength: 32,
    ),
    new OA\Property(
      property: 'qq',
      type: 'string',
      nullable: true,
      maxLength: 20,
    ),
    new OA\Property(
      property: 'avatar',
      type: 'string',
      nullable: true,
      description: '仅 null 清除头像；其他值忽略',
    ),
    new OA\Property(
      property: 'fivemId',
      type: 'string',
      nullable: true,
      description: '仅允许 null；其他值返回 400',
    ),
  ],
  minProperties: 1,
)]
#[OA\Schema(
  schema: 'AccountAvatarRequest',
  type: 'object',
  required: ['imageBase64', 'mediaType'],
  properties: [
    new OA\Property(
      property: 'imageBase64',
      type: 'string',
      format: 'byte',
      description: '无 data: 前缀的原始图片 base64 字节；解码后最多 2MiB',
    ),
    new OA\Property(
      property: 'mediaType',
      type: 'string',
      enum: ['image/png', 'image/jpeg', 'image/webp'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationVerifyRequest',
  type: 'object',
  required: ['email', 'code'],
  properties: [
    new OA\Property(property: 'email', type: 'string', format: 'email'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(
      property: 'captcha',
      ref: '#/components/schemas/AccountCaptchaAnswer',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationCompleteRequest',
  type: 'object',
  required: ['completionToken', 'nickname', 'password'],
  properties: [
    new OA\Property(
      property: 'completionToken',
      type: 'string',
      description: '十分钟一次性完成令牌',
    ),
    new OA\Property(
      property: 'nickname',
      type: 'string',
      minLength: 1,
      maxLength: 32,
    ),
    new OA\Property(
      property: 'password',
      type: 'string',
      format: 'password',
      minLength: 8,
      maxLength: 64,
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountPasswordRequest',
  type: 'object',
  required: ['old_password', 'new_password'],
  properties: [
    new OA\Property(
      property: 'old_password',
      type: 'string',
      format: 'password',
    ),
    new OA\Property(
      property: 'new_password',
      type: 'string',
      format: 'password',
      minLength: 6,
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountLoginUser',
  type: 'object',
  required: ['id', 'email', 'username', 'avatar', 'platform_role'],
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'email', type: 'string', format: 'email'),
    new OA\Property(property: 'username', type: 'string'),
    new OA\Property(property: 'avatar', type: 'string', nullable: true),
    new OA\Property(
      property: 'platform_role',
      type: 'string',
      nullable: true,
      description: '注册后当前模型未刷新数据库默认值，此分支可为 null；登录从数据库读取后为字符串',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountLoginData',
  type: 'object',
  required: ['token', 'expires_in', 'user'],
  properties: [
    new OA\Property(property: 'token', type: 'string'),
    new OA\Property(
      property: 'expires_in',
      type: 'integer',
      description: '有效期秒数',
    ),
    new OA\Property(
      property: 'user',
      ref: '#/components/schemas/AccountLoginUser',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountLoginResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountLoginData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountMeData',
  type: 'object',
  required: [
    'id',
    'email',
    'username',
    'avatar',
    'platform_role',
    'qq',
    'last_login_time',
  ],
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'email', type: 'string', format: 'email'),
    new OA\Property(property: 'username', type: 'string'),
    new OA\Property(property: 'avatar', type: 'string', nullable: true),
    new OA\Property(property: 'platform_role', type: 'string'),
    new OA\Property(property: 'qq', type: 'string', nullable: true),
    new OA\Property(
      property: 'last_login_time',
      type: 'string',
      nullable: true,
      description: '数据库时间 Y-m-d H:i:s',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountMeResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountMeData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountEmptyResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'array',
      items: new OA\Items(type: 'string'),
      maxItems: 0,
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountEmailCodeResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      oneOf: [
        new OA\Schema(
          type: 'array',
          maxItems: 0,
          items: new OA\Items(type: 'string'),
        ),
        new OA\Schema(
          type: 'object',
          required: ['debug_code'],
          properties: [new OA\Property(property: 'debug_code', type: 'string')],
        ),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountCaptchaData',
  type: 'object',
  required: ['challengeId', 'imageBase64', 'expiresAt'],
  properties: [
    new OA\Property(property: 'challengeId', type: 'string'),
    new OA\Property(
      property: 'imageBase64',
      type: 'string',
      format: 'byte',
      description: 'PNG 图片字节 base64，无 data: 前缀',
    ),
    new OA\Property(
      property: 'expiresAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountCaptchaResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountCaptchaData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationCheckData',
  type: 'object',
  required: ['status', 'email', 'serverTime', 'resendAvailableAt'],
  properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['eligible']),
    new OA\Property(property: 'email', type: 'string', format: 'email'),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
    new OA\Property(
      property: 'resendAvailableAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationCheckResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountInvitationCheckData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationCodeData',
  type: 'object',
  required: ['status', 'serverTime', 'resendAvailableAt'],
  properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['accepted']),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
    new OA\Property(
      property: 'resendAvailableAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
    new OA\Property(
      property: 'debug_code',
      type: 'string',
      description: '邮件发送失败且 debug 开启时存在',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationCodeResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountInvitationCodeData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationVerifyData',
  type: 'object',
  required: [
    'status',
    'completionToken',
    'email',
    'nickname',
    'serverTime',
    'completionExpiresAt',
  ],
  properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['verified']),
    new OA\Property(property: 'completionToken', type: 'string'),
    new OA\Property(property: 'email', type: 'string'),
    new OA\Property(property: 'nickname', type: 'string'),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
    new OA\Property(
      property: 'completionExpiresAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationVerifyResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountInvitationVerifyData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationCompleteData',
  type: 'object',
  required: ['status', 'email'],
  properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['registered']),
    new OA\Property(property: 'email', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'AccountInvitationCompleteResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountInvitationCompleteData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountSession',
  type: 'object',
  required: [
    'id',
    'user_id',
    'login_type',
    'client_id',
    'device_name',
    'platform',
    'ip',
    'ua_hash',
    'scope',
    'status',
    'created_time',
    'last_used_time',
    'expires_time',
    'updated_time',
    'current',
  ],
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'user_id', type: 'integer'),
    new OA\Property(
      property: 'login_type',
      type: 'string',
      enum: ['password', 'oauth'],
    ),
    new OA\Property(property: 'client_id', type: 'string', nullable: true),
    new OA\Property(property: 'device_name', type: 'string'),
    new OA\Property(property: 'platform', type: 'string'),
    new OA\Property(property: 'ip', type: 'string'),
    new OA\Property(property: 'ua_hash', type: 'string'),
    new OA\Property(property: 'scope', type: 'string'),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['active', 'revoked', 'expired'],
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      nullable: true,
      description: '数据库时间 Y-m-d H:i:s',
    ),
    new OA\Property(
      property: 'last_used_time',
      type: 'string',
      nullable: true,
      description: '数据库时间 Y-m-d H:i:s',
    ),
    new OA\Property(
      property: 'expires_time',
      type: 'string',
      nullable: true,
      description: '数据库时间 Y-m-d H:i:s',
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      nullable: true,
      description: '数据库时间 Y-m-d H:i:s',
    ),
    new OA\Property(property: 'current', type: 'boolean'),
  ],
)]
#[OA\Schema(
  schema: 'AccountSessionsData',
  type: 'object',
  required: ['list'],
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/AccountSession'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountSessionsResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountSessionsData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountGrant',
  type: 'object',
  required: [
    'client_id',
    'app_name',
    'app_type',
    'app_status',
    'scopes',
    'status',
    'granted_time',
    'revoked_time',
  ],
  properties: [
    new OA\Property(property: 'client_id', type: 'string'),
    new OA\Property(property: 'app_name', type: 'string'),
    new OA\Property(property: 'app_type', type: 'string', nullable: true),
    new OA\Property(property: 'app_status', type: 'string', nullable: true),
    new OA\Property(
      property: 'scopes',
      type: 'array',
      items: new OA\Items(type: 'string'),
    ),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'granted_time', type: 'string', nullable: true),
    new OA\Property(property: 'revoked_time', type: 'string', nullable: true),
  ],
)]
#[OA\Schema(
  schema: 'AccountGrantsData',
  type: 'object',
  required: ['list'],
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/AccountGrant'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountGrantsResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountGrantsData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountRevokeAllData',
  type: 'object',
  required: ['revoked'],
  properties: [new OA\Property(property: 'revoked', type: 'integer')],
)]
#[OA\Schema(
  schema: 'AccountRevokeAllResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountRevokeAllData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountRevokedSessionsData',
  type: 'object',
  required: ['revoked_sessions'],
  properties: [new OA\Property(property: 'revoked_sessions', type: 'integer')],
)]
#[OA\Schema(
  schema: 'AccountRevokedSessionsResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/AccountRevokedSessionsData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'AccountUnhandledErrorBody',
  type: 'string',
  description: '现有异常处理器返回 JSON 编码的 {message} 文本，响应沿用 text/html 类型',
  example: '{"message":"请求过于频繁"}',
)]
final class AccountSchemas {}
