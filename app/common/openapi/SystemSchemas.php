<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 健康检查与首次部署初始化的数据结构。 */
#[OA\Schema(
  schema: 'SystemHealthData',
  type: 'object',
  required: ['status'],
  properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['ok']),
  ],
)]
#[OA\Schema(
  schema: 'SystemEnvironmentCheck',
  type: 'object',
  required: ['name', 'passed'],
  properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'passed', type: 'boolean'),
  ],
)]
#[OA\Schema(
  schema: 'SystemInstallationStatusData',
  type: 'object',
  required: ['installed'],
  description: '已安装仅返回 installed=true；未安装返回 installed=false、checks、ready。检查 PHP >=8.1、pdo_pgsql、redis、gd、mbstring、项目及 .env 可写、schema.sql 可读。install.lock 或已有 superadmin 均视为已安装；后者可补写标记。不返回连接凭据。',
  properties: [
    new OA\Property(property: 'installed', type: 'boolean'),
    new OA\Property(
      property: 'checks',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/SystemEnvironmentCheck'),
    ),
    new OA\Property(property: 'ready', type: 'boolean'),
  ],
)]
#[OA\Schema(
  schema: 'SystemInstallDatabaseInput',
  type: 'object',
  required: ['host', 'port', 'database', 'username', 'password'],
  properties: [
    new OA\Property(
      property: 'host',
      type: 'string',
      maxLength: 253,
      pattern: '^[a-zA-Z0-9.:-]+$',
    ),
    new OA\Property(
      property: 'port',
      oneOf: [
        new OA\Schema(type: 'integer', minimum: 1, maximum: 65535),
        new OA\Schema(type: 'string', pattern: '^[0-9]+$'),
      ],
      description: '接受整数或数字字符串；数值范围 1..65535',
    ),
    new OA\Property(
      property: 'database',
      type: 'string',
      pattern: '^[a-zA-Z0-9_-]{1,63}$',
    ),
    new OA\Property(
      property: 'username',
      type: 'string',
      maxLength: 63,
      minLength: 1,
    ),
    new OA\Property(
      property: 'password',
      type: 'string',
      writeOnly: true,
      description: '可为空，不提供真实示例',
    ),
  ],
)]
#[OA\Schema(
  schema: 'SystemInstallRedisInput',
  type: 'object',
  required: ['host', 'port', 'database', 'password'],
  properties: [
    new OA\Property(
      property: 'host',
      type: 'string',
      maxLength: 253,
      pattern: '^[a-zA-Z0-9.:-]+$',
    ),
    new OA\Property(
      property: 'port',
      oneOf: [
        new OA\Schema(type: 'integer', minimum: 1, maximum: 65535),
        new OA\Schema(type: 'string', pattern: '^[0-9]+$'),
      ],
      description: '接受整数或数字字符串；数值范围 1..65535',
    ),
    new OA\Property(
      property: 'database',
      oneOf: [
        new OA\Schema(type: 'integer', minimum: 0, maximum: 65535),
        new OA\Schema(type: 'string', pattern: '^[0-9]+$'),
      ],
      description: '接受整数或数字字符串；数值范围 0..65535',
    ),
    new OA\Property(
      property: 'password',
      type: 'string',
      writeOnly: true,
      description: '可为空，不提供真实示例',
    ),
  ],
)]
#[OA\Schema(
  schema: 'SystemInstallAdminInput',
  type: 'object',
  required: ['email', 'username', 'password', 'password_confirmation'],
  properties: [
    new OA\Property(
      property: 'email',
      type: 'string',
      format: 'email',
      maxLength: 255,
    ),
    new OA\Property(
      property: 'username',
      type: 'string',
      minLength: 1,
      maxLength: 64,
    ),
    new OA\Property(
      property: 'password',
      type: 'string',
      format: 'password',
      writeOnly: true,
      description: '12..72 字节，至少一个字母和数字；禁止控制字符',
    ),
    new OA\Property(
      property: 'password_confirmation',
      type: 'string',
      format: 'password',
      writeOnly: true,
      description: '必须与 password 完全一致',
    ),
  ],
)]
#[OA\Schema(
  schema: 'SystemInstallInput',
  type: 'object',
  required: ['database', 'redis', 'admin'],
  description: '连接信息/管理员字段仅接受字符串或整数且禁止控制字符；除 password 外 trim 后不能为空。写入 .env（存在 .env.local 时写入该本地覆盖文件）、生成 JWT 密钥并创建 install.lock；不导入 seed.sql。',
  properties: [
    new OA\Property(
      property: 'database',
      ref: '#/components/schemas/SystemInstallDatabaseInput',
    ),
    new OA\Property(
      property: 'redis',
      ref: '#/components/schemas/SystemInstallRedisInput',
    ),
    new OA\Property(
      property: 'admin',
      ref: '#/components/schemas/SystemInstallAdminInput',
    ),
    new OA\Property(
      property: 'force',
      type: 'boolean',
      default: false,
      description: '必须为 JSON 布尔值；true 在事务内重建 public 下全部 app_ 业务表',
    ),
    new OA\Property(
      property: 'force_confirmation',
      type: 'string',
      description: 'force=true 时必须精确等于“重置数据库”',
    ),
  ],
)]
#[OA\Schema(
  schema: 'SystemInstallData',
  type: 'object',
  required: ['installed'],
  properties: [
    new OA\Property(property: 'installed', type: 'boolean', enum: [true]),
  ],
)]
class SystemSchemas {}
