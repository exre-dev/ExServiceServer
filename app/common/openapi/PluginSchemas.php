<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 开放插件 API 的请求与响应结构。 */
#[OA\Schema(
  schema: 'PluginFeatures',
  oneOf: [
    new OA\Schema(
      type: 'object',
      additionalProperties: new OA\AdditionalProperties(type: 'boolean'),
    ),
    new OA\Schema(type: 'array', items: new OA\Items(type: 'boolean')),
  ],
  description: '功能键到布尔值的映射；PHP 空数组序列化为 []，从 0 开始的连续数字键会序列化为布尔数组。',
)]
#[OA\Schema(
  schema: 'PluginLicenseRequest',
  type: 'object',
  required: ['server_key_hash'],
  properties: [
    new OA\Property(
      property: 'bind_code',
      type: 'string',
      description: 'X-Bind-Code 请求头为空时的绑定码回退；请求头非空时优先使用请求头。',
    ),
    new OA\Property(
      property: 'server_key_hash',
      type: 'string',
      minLength: 1,
      description: 'SDK 发送 sv_licenseKey 的 SHA-256；后端仅校验非空，不强制长度或散列格式。',
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginLicenseResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['status', 'plugin', 'features', 'expires_time', 'sync_token'],
      properties: [
        new OA\Property(
          property: 'status',
          type: 'string',
          enum: [
            'active',
            'revoked',
            'expired',
            'disabled',
            'server_mismatch',
            'plugin_offline',
            'server_suspended',
          ],
        ),
        new OA\Property(property: 'plugin', type: 'string'),
        new OA\Property(
          property: 'features',
          ref: '#/components/schemas/PluginFeatures',
        ),
        new OA\Property(
          property: 'expires_time',
          type: 'string',
          nullable: true,
          description: '数据库字符串时间；null 或空字符串表示长期，不保证 RFC 3339 格式。',
        ),
        new OA\Property(
          property: 'sync_token',
          type: 'string',
          description: '用于同步的 MD5 字符串。',
        ),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginBindTicketRequest',
  type: 'object',
  required: ['ident'],
  properties: [
    new OA\Property(
      property: 'bind_code',
      type: 'string',
      description: 'X-Bind-Code 请求头为空时的绑定码回退；请求头非空时优先使用请求头。',
    ),
    new OA\Property(
      property: 'ident',
      type: 'string',
      minLength: 1,
      description: '游戏标识；去除两端空白后不能为空。',
    ),
    new OA\Property(
      property: 'ident_type',
      type: 'string',
      enum: ['fivem_license', 'steam', 'discord', 'qq'],
      default: 'fivem_license',
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginBindTicketResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['ticket', 'expires_in'],
      properties: [
        new OA\Property(
          property: 'ticket',
          type: 'string',
          pattern: '^[0-9]{6}$',
          description: '保留前导零的六位数字字符串。',
        ),
        new OA\Property(property: 'expires_in', type: 'integer', enum: [300]),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginMemberPermission',
  type: 'object',
  required: ['ident', 'allowed', 'features'],
  properties: [
    new OA\Property(property: 'ident', type: 'string'),
    new OA\Property(property: 'allowed', type: 'boolean'),
    new OA\Property(
      property: 'features',
      ref: '#/components/schemas/PluginFeatures',
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginPermissionBatchResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['list'],
      properties: [
        new OA\Property(
          property: 'list',
          type: 'array',
          items: new OA\Items(
            ref: '#/components/schemas/PluginMemberPermission',
          ),
        ),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginPermissionRevisionResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['rev'],
      properties: [new OA\Property(property: 'rev', type: 'integer')],
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginTelemetryRequest',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'bind_code',
      type: 'string',
      description: 'X-Bind-Code 请求头为空时的绑定码回退；请求头非空时优先使用请求头。',
    ),
    new OA\Property(
      property: 'event',
      type: 'string',
      default: '',
      description: '当前实现不校验必填，省略时记录空字符串。',
    ),
    new OA\Property(property: 'message', type: 'string', default: ''),
    new OA\Property(
      property: 'data',
      nullable: true,
      description: '调用方自定义遥测载荷，当前实现不限制结构；省略时为 null。',
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginTelemetryResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'array',
      maxItems: 0,
      items: new OA\Items(),
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginBindSelfRequest',
  type: 'object',
  required: ['ident'],
  properties: [
    new OA\Property(
      property: 'ident',
      type: 'string',
      minLength: 1,
      description: '游戏标识；去除两端空白后不能为空。',
    ),
    new OA\Property(
      property: 'ident_type',
      type: 'string',
      enum: ['fivem_license', 'steam', 'discord', 'qq'],
      default: 'fivem_license',
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginBindSelfResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      oneOf: [
        new OA\Schema(
          type: 'object',
          required: ['ident_tail', 'joined_server'],
          properties: [
            new OA\Property(
              property: 'ident_tail',
              type: 'string',
              description: '省略号加标识末尾最多八个字符。',
            ),
            new OA\Property(property: 'joined_server', type: 'boolean'),
          ],
        ),
        new OA\Schema(
          type: 'array',
          maxItems: 0,
          items: new OA\Items(),
          description: '标识已属于当前账号时返回 []，msg 为已绑定提示。',
        ),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginOAuthIdent',
  type: 'object',
  required: ['type', 'value'],
  properties: [
    new OA\Property(property: 'type', type: 'string'),
    new OA\Property(property: 'value', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'PluginOAuthServer',
  type: 'object',
  required: ['name', 'slug', 'role'],
  properties: [
    new OA\Property(property: 'name', type: 'string', nullable: true),
    new OA\Property(property: 'slug', type: 'string', nullable: true),
    new OA\Property(property: 'role', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'PluginOAuthPermission',
  type: 'object',
  required: ['server', 'plugin', 'allowed', 'features'],
  properties: [
    new OA\Property(property: 'server', type: 'string', nullable: true),
    new OA\Property(property: 'plugin', type: 'string'),
    new OA\Property(property: 'allowed', type: 'boolean'),
    new OA\Property(
      property: 'features',
      ref: '#/components/schemas/PluginFeatures',
    ),
  ],
)]
#[OA\Schema(
  schema: 'PluginOAuthUserInfoResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      type: 'object',
      required: ['id', 'username', 'avatar'],
      properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'username', type: 'string'),
        new OA\Property(property: 'avatar', type: 'string'),
        new OA\Property(
          property: 'email',
          type: 'string',
          description: '仅 user.email scope 返回。',
        ),
        new OA\Property(
          property: 'idents',
          type: 'array',
          items: new OA\Items(ref: '#/components/schemas/PluginOAuthIdent'),
          description: '仅 idents.read scope 返回。',
        ),
        new OA\Property(
          property: 'servers',
          type: 'array',
          items: new OA\Items(ref: '#/components/schemas/PluginOAuthServer'),
          description: '仅 servers.read scope 返回。',
        ),
        new OA\Property(
          property: 'perms',
          type: 'array',
          items: new OA\Items(
            ref: '#/components/schemas/PluginOAuthPermission',
          ),
          description: '仅 perms.read scope 返回。',
        ),
      ],
    ),
  ],
)]
class PluginSchemas {}
