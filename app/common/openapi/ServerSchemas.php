<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 空间管理接口的数据结构。 */
#[OA\Schema(
  schema: 'ServerAccount',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'slug', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'logo', type: 'string'),
    new OA\Property(property: 'owner_user_id', type: 'integer'),
    new OA\Property(property: 'contact_qq', type: 'string'),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['active', 'suspended', 'closed'],
    ),
    new OA\Property(
      property: 'note',
      type: 'string',
      description: '当前完整模型序列化包含平台备注字段',
    ),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
    new OA\Property(property: 'updated_time', type: 'string', nullable: true),
  ],
)]
#[OA\Schema(
  schema: 'ServerManagedSpace',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'slug', type: 'string'),
    new OA\Property(property: 'logo', type: 'string'),
    new OA\Property(
      property: 'my_role',
      type: 'string',
      enum: ['owner', 'admin'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerMineData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerManagedSpace'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerOverviewData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'server',
      ref: '#/components/schemas/ServerAccount',
    ),
    new OA\Property(
      property: 'my_role',
      type: 'string',
      enum: ['owner', 'admin', 'member'],
    ),
    new OA\Property(property: 'member_count', type: 'integer'),
    new OA\Property(property: 'binding_count', type: 'integer'),
    new OA\Property(property: 'ident_count', type: 'integer'),
  ],
)]
#[OA\Schema(
  schema: 'ServerSettingsInput',
  type: 'object',
  properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'contact_qq', type: 'string'),
    new OA\Property(property: 'logo', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'ServerUserSummary',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'email', type: 'string'),
    new OA\Property(property: 'username', type: 'string'),
    new OA\Property(property: 'avatar', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'ServerMemberUser',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'email', type: 'string'),
    new OA\Property(property: 'username', type: 'string'),
    new OA\Property(property: 'avatar', type: 'string'),
    new OA\Property(property: 'qq', type: 'string', nullable: true),
  ],
)]
#[OA\Schema(
  schema: 'ServerMember',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'server_id', type: 'integer'),
    new OA\Property(property: 'user_id', type: 'integer'),
    new OA\Property(
      property: 'role_key',
      type: 'string',
      enum: ['owner', 'admin', 'member'],
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['active', 'banned', 'left'],
    ),
    new OA\Property(
      property: 'source',
      type: 'string',
      enum: ['grant', 'manual', 'bind'],
    ),
    new OA\Property(property: 'joined_time', type: 'string', nullable: true),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
    new OA\Property(property: 'updated_time', type: 'string', nullable: true),
    new OA\Property(
      property: 'user',
      ref: '#/components/schemas/ServerMemberUser',
      nullable: true,
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerMembersData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerMember'),
    ),
    new OA\Property(
      property: 'my_role',
      type: 'string',
      enum: ['owner', 'admin', 'member'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerMemberAddInput',
  type: 'object',
  required: ['keyword'],
  properties: [
    new OA\Property(
      property: 'keyword',
      type: 'string',
      description: '邮箱或 QQ 精确匹配；去除首尾空白后非空',
    ),
    new OA\Property(
      property: 'role',
      type: 'string',
      enum: ['admin', 'member'],
      default: 'member',
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerMemberRoleInput',
  type: 'object',
  required: ['role'],
  properties: [
    new OA\Property(
      property: 'role',
      type: 'string',
      enum: ['admin', 'member'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerIdent',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'user_id', type: 'integer'),
    new OA\Property(
      property: 'ident_type',
      type: 'string',
      enum: ['fivem_license', 'steam', 'discord', 'qq'],
    ),
    new OA\Property(
      property: 'ident_value',
      type: 'string',
      description: '列表当前返回完整游戏标识',
    ),
    new OA\Property(
      property: 'bound_server_id',
      type: 'integer',
      nullable: true,
    ),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
    new OA\Property(
      property: 'user',
      ref: '#/components/schemas/ServerUserSummary',
      nullable: true,
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerIdentsData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerIdent'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerIdentBindInput',
  type: 'object',
  required: ['ticket', 'user_id'],
  properties: [
    new OA\Property(
      property: 'ticket',
      type: 'string',
      description: '游戏中生成的一次性 ticket，300 秒有效；取出后即消费，后续校验失败也不恢复',
    ),
    new OA\Property(property: 'user_id', type: 'integer', minimum: 1),
  ],
)]
#[OA\Schema(
  schema: 'ServerBoundUser',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'username', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'ServerIdentBoundData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'ident_tail',
      type: 'string',
      description: '省略号加标识最后 8 位',
    ),
    new OA\Property(
      property: 'user',
      ref: '#/components/schemas/ServerBoundUser',
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerPluginOption',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'slug', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'ServerBindingPlugin',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'slug', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['draft', 'online', 'offline'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerBinding',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'server_id', type: 'integer'),
    new OA\Property(property: 'plugin_id', type: 'integer'),
    new OA\Property(
      property: 'code',
      type: 'string',
      description: '完整绑定码，仅空间管理端返回；无真实凭据示例',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['active', 'revoked', 'disabled'],
    ),
    new OA\Property(
      property: 'server_key_hash',
      type: 'string',
      description: '当前序列化包含首次绑定的服务器密钥 SHA-256；未绑定为空字符串',
    ),
    new OA\Property(
      property: 'features',
      description: '功能键到布尔值的映射；空映射经 array cast 可输出 []',
      oneOf: [
        new OA\Schema(
          type: 'object',
          additionalProperties: new OA\AdditionalProperties(type: 'boolean'),
        ),
        new OA\Schema(
          type: 'array',
          maxItems: 0,
          items: new OA\Items(type: 'boolean'),
        ),
      ],
    ),
    new OA\Property(property: 'expires_time', type: 'string', nullable: true),
    new OA\Property(property: 'last_heartbeat', type: 'string', nullable: true),
    new OA\Property(property: 'last_heartbeat_ip', type: 'string'),
    new OA\Property(property: 'created_by', type: 'integer', nullable: true),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
    new OA\Property(property: 'updated_time', type: 'string', nullable: true),
    new OA\Property(
      property: 'plugin',
      ref: '#/components/schemas/ServerBindingPlugin',
      nullable: true,
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerBindingsData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerBinding'),
    ),
    new OA\Property(
      property: 'plugin_options',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerPluginOption'),
    ),
    new OA\Property(
      property: 'server_status',
      type: 'string',
      nullable: true,
      enum: ['active', 'suspended', 'closed'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerPluginIdInput',
  type: 'object',
  required: ['plugin_id'],
  properties: [new OA\Property(property: 'plugin_id', type: 'integer')],
)]
#[OA\Schema(
  schema: 'ServerBindingCodeData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'code',
      type: 'string',
      pattern: '^EX-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{5}(-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{5}){3}$',
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerFeature',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'plugin_id', type: 'integer'),
    new OA\Property(property: 'feature_key', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'default_enabled', type: 'boolean'),
  ],
)]
#[OA\Schema(
  schema: 'ServerFeatureSummary',
  type: 'object',
  properties: [
    new OA\Property(property: 'feature_key', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'default_enabled', type: 'boolean'),
  ],
)]
#[OA\Schema(
  schema: 'ServerPermissionRow',
  type: 'object',
  properties: [
    new OA\Property(property: 'user_id', type: 'integer'),
    new OA\Property(property: 'username', type: 'string', nullable: true),
    new OA\Property(property: 'email', type: 'string', nullable: true),
    new OA\Property(
      property: 'role_key',
      type: 'string',
      enum: ['owner', 'admin', 'member'],
    ),
    new OA\Property(
      property: 'allowed',
      type: 'boolean',
      description: '没有覆盖记录时为 true',
    ),
    new OA\Property(
      property: 'features',
      description: '功能键到布尔值的映射或布尔数组；连续数字键经 PHP 数组序列化可输出非空数组，null 表示无功能覆盖',
      oneOf: [
        new OA\Schema(
          type: 'object',
          nullable: true,
          additionalProperties: new OA\AdditionalProperties(type: 'boolean'),
        ),
        new OA\Schema(type: 'array', items: new OA\Items(type: 'boolean')),
      ],
    ),
    new OA\Property(property: 'has_perm_row', type: 'boolean'),
  ],
)]
#[OA\Schema(
  schema: 'ServerPermissionBinding',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'plugin_id', type: 'integer'),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['active', 'revoked', 'disabled'],
    ),
    new OA\Property(
      property: 'features',
      description: '功能键到布尔值的映射；空映射经 array cast 可输出 []',
      oneOf: [
        new OA\Schema(
          type: 'object',
          additionalProperties: new OA\AdditionalProperties(type: 'boolean'),
        ),
        new OA\Schema(
          type: 'array',
          maxItems: 0,
          items: new OA\Items(type: 'boolean'),
        ),
      ],
    ),
    new OA\Property(property: 'expires_time', type: 'string', nullable: true),
  ],
)]
#[OA\Schema(
  schema: 'ServerPermissionMatrixData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'binding',
      ref: '#/components/schemas/ServerPermissionBinding',
    ),
    new OA\Property(
      property: 'features',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerFeature'),
    ),
    new OA\Property(
      property: 'rows',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerPermissionRow'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerPermissionMember',
  type: 'object',
  properties: [
    new OA\Property(property: 'user_id', type: 'integer'),
    new OA\Property(property: 'username', type: 'string', nullable: true),
    new OA\Property(property: 'email', type: 'string', nullable: true),
    new OA\Property(
      property: 'avatar',
      type: 'string',
      nullable: true,
      description: '没有插件绑定时此字段省略',
    ),
    new OA\Property(
      property: 'role_key',
      type: 'string',
      enum: ['owner', 'admin', 'member'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerMemberPermission',
  type: 'object',
  properties: [
    new OA\Property(property: 'binding_id', type: 'integer'),
    new OA\Property(
      property: 'binding_status',
      type: 'string',
      enum: ['active', 'revoked', 'disabled'],
    ),
    new OA\Property(property: 'plugin_id', type: 'integer'),
    new OA\Property(property: 'plugin_name', type: 'string', nullable: true),
    new OA\Property(property: 'plugin_slug', type: 'string', nullable: true),
    new OA\Property(property: 'plugin_status', type: 'string', nullable: true),
    new OA\Property(property: 'allowed', type: 'boolean'),
    new OA\Property(
      property: 'features',
      description: '功能键到布尔值的映射或布尔数组；连续数字键经 PHP 数组序列化可输出非空数组，null 表示无功能覆盖',
      oneOf: [
        new OA\Schema(
          type: 'object',
          nullable: true,
          additionalProperties: new OA\AdditionalProperties(type: 'boolean'),
        ),
        new OA\Schema(type: 'array', items: new OA\Items(type: 'boolean')),
      ],
    ),
    new OA\Property(property: 'has_perm_row', type: 'boolean'),
    new OA\Property(
      property: 'feature_list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerFeatureSummary'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerMemberPermissionsData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'member',
      ref: '#/components/schemas/ServerPermissionMember',
    ),
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerMemberPermission'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerPermissionTargetInput',
  type: 'object',
  required: ['plugin_id', 'user_id'],
  properties: [
    new OA\Property(property: 'plugin_id', type: 'integer'),
    new OA\Property(property: 'user_id', type: 'integer'),
  ],
)]
#[OA\Schema(
  schema: 'ServerPermissionSaveInput',
  type: 'object',
  required: ['plugin_id', 'user_id'],
  description: '仅保留该插件已知功能键并转为 bool；features 缺失或 null 清除功能覆盖，空映射保留空覆盖记录。',
  properties: [
    new OA\Property(property: 'plugin_id', type: 'integer'),
    new OA\Property(property: 'user_id', type: 'integer'),
    new OA\Property(property: 'allowed', type: 'boolean', default: true),
    new OA\Property(
      property: 'features',
      description: '功能键到布尔值的映射或布尔数组；连续数字键经 PHP 数组序列化可输出非空数组，null 表示无功能覆盖',
      oneOf: [
        new OA\Schema(
          type: 'object',
          nullable: true,
          additionalProperties: new OA\AdditionalProperties(type: 'boolean'),
        ),
        new OA\Schema(type: 'array', items: new OA\Items(type: 'boolean')),
      ],
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerPermissionPushInput',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'plugin_id',
      type: 'integer',
      default: 0,
      description: '大于 0 仅下发指定插件，省略或不大于 0 下发全部绑定插件',
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerPermissionPushData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'plugins',
      type: 'array',
      items: new OA\Items(type: 'string'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerClient',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'client_id', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(
      property: 'scopes',
      type: 'array',
      items: new OA\Items(type: 'string'),
    ),
    new OA\Property(
      property: 'client_status',
      type: 'string',
      enum: ['active', 'suspended'],
    ),
    new OA\Property(
      property: 'link_status',
      type: 'string',
      enum: ['active', 'disconnected'],
    ),
    new OA\Property(
      property: 'disconnected_time',
      type: 'string',
      nullable: true,
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerClientsData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ServerClient'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ServerClientInput',
  type: 'object',
  required: ['client_id'],
  properties: [new OA\Property(property: 'client_id', type: 'string')],
)]
#[OA\Schema(
  schema: 'ServerCreatedIdData',
  type: 'object',
  properties: [new OA\Property(property: 'id', type: 'integer')],
)]
#[OA\Schema(
  schema: 'ServerVersionData',
  type: 'object',
  properties: [new OA\Property(property: 'version', type: 'integer')],
)]
class ServerSchemas {}
