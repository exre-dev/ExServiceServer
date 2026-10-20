<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 平台管理端契约结构，依据现有控制器和模型序列化行为。 */
#[OA\Schema(
  schema: 'AdminUser',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'id',
      type: 'integer',
      description: '用户唯一标识',
    ),
    new OA\Property(
      property: 'email',
      type: 'string',
      description: '登录邮箱,全局唯一',
    ),
    new OA\Property(
      property: 'username',
      type: 'string',
      description: '显示昵称',
    ),
    new OA\Property(
      property: 'avatar',
      type: 'string',
      description: '头像地址',
    ),
    new OA\Property(
      property: 'qq',
      type: 'string',
      description: '联系QQ号,可空,唯一',
      nullable: true,
    ),
    new OA\Property(
      property: 'level',
      type: 'integer',
      description: '用户等级,预留',
    ),
    new OA\Property(
      property: 'platform_role',
      type: 'string',
      description: '平台角色: \'\'/ops/superadmin',
    ),
    new OA\Property(
      property: 'ban',
      type: 'integer',
      description: '封禁标记: 0正常 1封禁',
    ),
    new OA\Property(
      property: 'last_login_time',
      type: 'string',
      description: '最近登录时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
  ],
  description: '用户完整序列化字段，不返回 password_hash。',
)]
#[OA\Schema(
  schema: 'AdminOAuthClient',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'client_id',
      type: 'string',
      description: '公开client_id',
    ),
    new OA\Property(property: 'name', type: 'string', description: '应用名称'),
    new OA\Property(
      property: 'type',
      type: 'string',
      description: '类型: third_party/plugin',
    ),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: 'plugin类应用归属空间id,空=平台级',
      nullable: true,
    ),
    new OA\Property(
      property: 'redirect_uris',
      type: 'array',
      items: new OA\Items(type: 'string'),
    ),
    new OA\Property(
      property: 'scopes',
      type: 'array',
      items: new OA\Items(type: 'string'),
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/suspended',
    ),
    new OA\Property(
      property: 'note',
      type: 'string',
      description: '对接备注,仅超管可见',
    ),
    new OA\Property(
      property: 'created_by',
      type: 'integer',
      description: '创建者用户id',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
  ],
  description: '应用完整序列化字段，不返回 client_secret_hash。',
)]
#[OA\Schema(
  schema: 'AdminPluginBinding',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: '空间id',
    ),
    new OA\Property(
      property: 'plugin_id',
      type: 'integer',
      description: '插件id',
    ),
    new OA\Property(
      property: 'code',
      type: 'string',
      description: '绑定码 EX-XXXX-XXXX-XXXX-XXXX-XXXX',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/revoked/disabled',
    ),
    new OA\Property(
      property: 'server_key_hash',
      type: 'string',
      description: '首次校验锁定的sv_licenseKey SHA-256',
    ),
    new OA\Property(
      property: 'features',
      description: '功能键到布尔值的映射；空映射经模型 array cast 输出 []',
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
    new OA\Property(
      property: 'expires_time',
      type: 'string',
      description: '过期时间,空=长期',
      nullable: true,
    ),
    new OA\Property(
      property: 'last_heartbeat',
      type: 'string',
      description: '最近心跳时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'last_heartbeat_ip',
      type: 'string',
      description: '最近心跳来源IP',
    ),
    new OA\Property(
      property: 'created_by',
      type: 'integer',
      description: '生成者用户id',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
  ],
  description: '绑定码完整序列化字段，包括当前模型未隐藏的 server_key_hash。',
)]
#[OA\Schema(
  schema: 'AdminPlugin',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'slug',
      type: 'string',
      description: '插件唯一标识,如 ex-tuner',
    ),
    new OA\Property(property: 'name', type: 'string', description: '插件名称'),
    new OA\Property(
      property: 'summary',
      type: 'string',
      description: '一句话简介',
    ),
    new OA\Property(
      property: 'description_md',
      type: 'string',
      description: '详情(Markdown)',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: draft/online/offline',
    ),
    new OA\Property(
      property: 'latest_version',
      type: 'string',
      description: '最新版本号',
    ),
    new OA\Property(
      property: 'doc_category_id',
      type: 'integer',
      description: '平台文档默认分类id,预留',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
  ],
  description: '插件目录完整字段。',
)]
#[OA\Schema(
  schema: 'AdminPluginFeature',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'plugin_id',
      type: 'integer',
      description: '所属插件id',
    ),
    new OA\Property(
      property: 'feature_key',
      type: 'string',
      description: '功能键,如 nitro',
    ),
    new OA\Property(
      property: 'name',
      type: 'string',
      description: '功能中文名',
    ),
    new OA\Property(
      property: 'default_enabled',
      type: 'boolean',
      description: '空间未显式配置时是否默认开启',
    ),
  ],
  description: '插件功能点字典。',
)]
#[OA\Schema(
  schema: 'AdminServerAccount',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'id',
      type: 'integer',
      description: '空间唯一标识',
    ),
    new OA\Property(property: 'name', type: 'string', description: '空间名称'),
    new OA\Property(
      property: 'slug',
      type: 'string',
      description: '空间短标识,全局唯一',
    ),
    new OA\Property(
      property: 'description',
      type: 'string',
      description: '空间介绍',
    ),
    new OA\Property(
      property: 'logo',
      type: 'string',
      description: '空间图标地址',
    ),
    new OA\Property(
      property: 'owner_user_id',
      type: 'integer',
      description: 'Owner用户id',
    ),
    new OA\Property(
      property: 'contact_qq',
      type: 'string',
      description: '联系QQ',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/suspended/closed',
    ),
    new OA\Property(
      property: 'note',
      type: 'string',
      description: '平台备注,仅超管可见',
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
  ],
  description: '空间完整字段。',
)]
#[OA\Schema(
  schema: 'AdminServerMember',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: '空间id',
    ),
    new OA\Property(
      property: 'user_id',
      type: 'integer',
      description: '用户id',
    ),
    new OA\Property(
      property: 'role_key',
      type: 'string',
      description: '空间角色: owner/admin/member',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/banned/left',
    ),
    new OA\Property(
      property: 'source',
      type: 'string',
      description: '入空间方式: grant/manual/bind',
    ),
    new OA\Property(
      property: 'joined_time',
      type: 'string',
      description: '加入时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
  ],
  description: '空间成员关系完整字段。',
)]
#[OA\Schema(
  schema: 'AdminAuthSession',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'id',
      type: 'integer',
      description: '会话id,即JWT中的sid',
    ),
    new OA\Property(
      property: 'user_id',
      type: 'integer',
      description: '归属用户id',
    ),
    new OA\Property(
      property: 'login_type',
      type: 'string',
      description: '登录方式: password/oauth',
    ),
    new OA\Property(
      property: 'client_id',
      type: 'string',
      description: 'OAuth时关联的应用client_id',
      nullable: true,
    ),
    new OA\Property(
      property: 'device_name',
      type: 'string',
      description: '设备名(UA自动识别)',
    ),
    new OA\Property(
      property: 'platform',
      type: 'string',
      description: '平台: web/fivem/third_party',
    ),
    new OA\Property(property: 'ip', type: 'string', description: '登录来源IP'),
    new OA\Property(property: 'ua_hash', type: 'string', description: 'UA摘要'),
    new OA\Property(
      property: 'scope',
      type: 'string',
      description: 'OAuth时授予的scope',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/revoked/expired',
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '登录时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'last_used_time',
      type: 'string',
      description: '最近使用时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'expires_time',
      type: 'string',
      description: '会话过期时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
  ],
  description: '会话完整字段，包括当前模型未隐藏的 ua_hash。',
)]
#[OA\Schema(
  schema: 'AdminAuditLog',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'actor_user_id',
      type: 'integer',
      description: '操作者用户id,系统操作为空',
      nullable: true,
    ),
    new OA\Property(
      property: 'actor_type',
      type: 'string',
      description: '操作者类型: platform/server/user/client',
    ),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: '相关空间id',
      nullable: true,
    ),
    new OA\Property(
      property: 'client_id',
      type: 'string',
      description: '相关应用client_id',
      nullable: true,
    ),
    new OA\Property(
      property: 'action',
      type: 'string',
      description: '动作名,如 user.login / binding.revoke',
    ),
    new OA\Property(
      property: 'target_type',
      type: 'string',
      description: '目标类型',
    ),
    new OA\Property(
      property: 'target_id',
      type: 'string',
      description: '目标id',
    ),
    new OA\Property(
      property: 'detail',
      type: 'object',
      description: '详情JSON',
      nullable: true,
      additionalProperties: true,
    ),
    new OA\Property(property: 'ip', type: 'string', description: '来源IP'),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '记录时间',
      nullable: true,
    ),
  ],
  description: '审计日志；detail 为各动作自己的 JSON 详情。',
)]
#[OA\Schema(
  schema: 'AdminDoc',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'owner_type',
      type: 'string',
      description: '归属: platform/server',
    ),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: 'owner_type=server时的空间id',
      nullable: true,
    ),
    new OA\Property(
      property: 'category_id',
      type: 'integer',
      description: '分类id',
      nullable: true,
    ),
    new OA\Property(property: 'title', type: 'string', description: '标题'),
    new OA\Property(
      property: 'slug',
      type: 'string',
      description: '全局唯一URL标识',
    ),
    new OA\Property(
      property: 'content_md',
      type: 'string',
      description: '正文Markdown',
    ),
    new OA\Property(
      property: 'visibility',
      type: 'string',
      description: '可见性: public/login/members/roles/servers/tags',
    ),
    new OA\Property(
      property: 'visible_roles',
      type: 'array',
      items: new OA\Items(type: 'string'),
      nullable: true,
    ),
    new OA\Property(
      property: 'visible_servers',
      type: 'array',
      items: new OA\Items(type: 'integer'),
      nullable: true,
    ),
    new OA\Property(
      property: 'visible_tags',
      type: 'array',
      items: new OA\Items(type: 'integer'),
      nullable: true,
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: draft/published',
    ),
    new OA\Property(
      property: 'author_user_id',
      type: 'integer',
      description: '作者用户id',
      nullable: true,
    ),
    new OA\Property(
      property: 'version',
      type: 'integer',
      description: '版本号',
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
  ],
  description: '平台文档完整字段。',
)]
#[OA\Schema(
  schema: 'AdminDocCategory',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'owner_type',
      type: 'string',
      description: '归属: platform/server',
    ),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: 'owner_type=server时的空间id',
      nullable: true,
    ),
    new OA\Property(property: 'name', type: 'string', description: '分类名'),
    new OA\Property(
      property: 'sort',
      type: 'integer',
      description: '排序,小的在前',
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
  ],
  description: '平台文档分类。',
)]
#[OA\Schema(
  schema: 'AdminTag',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'name',
      type: 'string',
      description: '标签名,全局唯一',
    ),
    new OA\Property(property: 'note', type: 'string', description: '备注'),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
  ],
  description: '标签字典。',
)]
#[OA\Schema(
  schema: 'AdminUserBrief',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'email', type: 'string'),
    new OA\Property(property: 'username', type: 'string'),
  ],
  description: '关联用户的选定字段。',
)]
#[OA\Schema(
  schema: 'AdminMemberUser',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'email', type: 'string'),
    new OA\Property(property: 'username', type: 'string'),
    new OA\Property(property: 'avatar', type: 'string'),
  ],
  description: '空间详情中的成员用户选定字段。',
)]
#[OA\Schema(
  schema: 'AdminServerBrief',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'slug', type: 'string'),
  ],
  description: '空间下拉选项和应用归属空间。',
)]
#[OA\Schema(
  schema: 'AdminServerStateBrief',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'slug', type: 'string'),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['active', 'suspended', 'closed'],
    ),
  ],
  description: '绑定码和用户成员关系关联的空间字段。',
)]
#[OA\Schema(
  schema: 'AdminPluginBrief',
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
  description: '绑定码关联插件字段；插件下拉选项仅包含在线插件。',
)]
#[OA\Schema(
  schema: 'AdminServerWithOwner',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'id',
      type: 'integer',
      description: '空间唯一标识',
    ),
    new OA\Property(property: 'name', type: 'string', description: '空间名称'),
    new OA\Property(
      property: 'slug',
      type: 'string',
      description: '空间短标识,全局唯一',
    ),
    new OA\Property(
      property: 'description',
      type: 'string',
      description: '空间介绍',
    ),
    new OA\Property(
      property: 'logo',
      type: 'string',
      description: '空间图标地址',
    ),
    new OA\Property(
      property: 'owner_user_id',
      type: 'integer',
      description: 'Owner用户id',
    ),
    new OA\Property(
      property: 'contact_qq',
      type: 'string',
      description: '联系QQ',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/suspended/closed',
    ),
    new OA\Property(
      property: 'note',
      type: 'string',
      description: '平台备注,仅超管可见',
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'owner',
      ref: '#/components/schemas/AdminUserBrief',
      nullable: true,
    ),
  ],
  description: '空间完整字段及所有者选定字段。',
)]
#[OA\Schema(
  schema: 'AdminServerListItem',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'id',
      type: 'integer',
      description: '空间唯一标识',
    ),
    new OA\Property(property: 'name', type: 'string', description: '空间名称'),
    new OA\Property(
      property: 'slug',
      type: 'string',
      description: '空间短标识,全局唯一',
    ),
    new OA\Property(
      property: 'description',
      type: 'string',
      description: '空间介绍',
    ),
    new OA\Property(
      property: 'logo',
      type: 'string',
      description: '空间图标地址',
    ),
    new OA\Property(
      property: 'owner_user_id',
      type: 'integer',
      description: 'Owner用户id',
    ),
    new OA\Property(
      property: 'contact_qq',
      type: 'string',
      description: '联系QQ',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/suspended/closed',
    ),
    new OA\Property(
      property: 'note',
      type: 'string',
      description: '平台备注,仅超管可见',
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'owner',
      ref: '#/components/schemas/AdminUserBrief',
      nullable: true,
    ),
    new OA\Property(property: 'members_count', type: 'integer'),
    new OA\Property(property: 'bindings_count', type: 'integer'),
  ],
  description: '空间列表，附成员和绑定码数量。',
)]
#[OA\Schema(
  schema: 'AdminMemberWithUser',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: '空间id',
    ),
    new OA\Property(
      property: 'user_id',
      type: 'integer',
      description: '用户id',
    ),
    new OA\Property(
      property: 'role_key',
      type: 'string',
      description: '空间角色: owner/admin/member',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/banned/left',
    ),
    new OA\Property(
      property: 'source',
      type: 'string',
      description: '入空间方式: grant/manual/bind',
    ),
    new OA\Property(
      property: 'joined_time',
      type: 'string',
      description: '加入时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'user',
      ref: '#/components/schemas/AdminMemberUser',
      nullable: true,
    ),
  ],
  description: '空间详情成员及关联用户。',
)]
#[OA\Schema(
  schema: 'AdminMemberWithServer',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: '空间id',
    ),
    new OA\Property(
      property: 'user_id',
      type: 'integer',
      description: '用户id',
    ),
    new OA\Property(
      property: 'role_key',
      type: 'string',
      description: '空间角色: owner/admin/member',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/banned/left',
    ),
    new OA\Property(
      property: 'source',
      type: 'string',
      description: '入空间方式: grant/manual/bind',
    ),
    new OA\Property(
      property: 'joined_time',
      type: 'string',
      description: '加入时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'server',
      ref: '#/components/schemas/AdminServerStateBrief',
      nullable: true,
    ),
  ],
  description: '用户详情中的成员关系和空间。',
)]
#[OA\Schema(
  schema: 'AdminBindingWithPlugin',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: '空间id',
    ),
    new OA\Property(
      property: 'plugin_id',
      type: 'integer',
      description: '插件id',
    ),
    new OA\Property(
      property: 'code',
      type: 'string',
      description: '绑定码 EX-XXXX-XXXX-XXXX-XXXX-XXXX',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/revoked/disabled',
    ),
    new OA\Property(
      property: 'server_key_hash',
      type: 'string',
      description: '首次校验锁定的sv_licenseKey SHA-256',
    ),
    new OA\Property(
      property: 'features',
      description: '功能键到布尔值的映射；空映射经模型 array cast 输出 []',
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
    new OA\Property(
      property: 'expires_time',
      type: 'string',
      description: '过期时间,空=长期',
      nullable: true,
    ),
    new OA\Property(
      property: 'last_heartbeat',
      type: 'string',
      description: '最近心跳时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'last_heartbeat_ip',
      type: 'string',
      description: '最近心跳来源IP',
    ),
    new OA\Property(
      property: 'created_by',
      type: 'integer',
      description: '生成者用户id',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'plugin',
      ref: '#/components/schemas/AdminPluginBrief',
      nullable: true,
    ),
  ],
  description: '空间详情中的绑定码及关联插件。',
)]
#[OA\Schema(
  schema: 'AdminBindingListItem',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: '空间id',
    ),
    new OA\Property(
      property: 'plugin_id',
      type: 'integer',
      description: '插件id',
    ),
    new OA\Property(
      property: 'code',
      type: 'string',
      description: '绑定码 EX-XXXX-XXXX-XXXX-XXXX-XXXX',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/revoked/disabled',
    ),
    new OA\Property(
      property: 'server_key_hash',
      type: 'string',
      description: '首次校验锁定的sv_licenseKey SHA-256',
    ),
    new OA\Property(
      property: 'features',
      description: '功能键到布尔值的映射；空映射经模型 array cast 输出 []',
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
    new OA\Property(
      property: 'expires_time',
      type: 'string',
      description: '过期时间,空=长期',
      nullable: true,
    ),
    new OA\Property(
      property: 'last_heartbeat',
      type: 'string',
      description: '最近心跳时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'last_heartbeat_ip',
      type: 'string',
      description: '最近心跳来源IP',
    ),
    new OA\Property(
      property: 'created_by',
      type: 'integer',
      description: '生成者用户id',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'plugin',
      ref: '#/components/schemas/AdminPluginBrief',
      nullable: true,
    ),
    new OA\Property(
      property: 'server',
      ref: '#/components/schemas/AdminServerStateBrief',
      nullable: true,
    ),
    new OA\Property(
      property: 'health',
      type: 'string',
      enum: ['inactive', 'never', 'stale', 'ok'],
    ),
  ],
  description: '全局绑定码列表；health：inactive 非 active、never 未心跳、stale 超过1800秒、ok 正常。',
)]
#[OA\Schema(
  schema: 'AdminPluginListItem',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'slug',
      type: 'string',
      description: '插件唯一标识,如 ex-tuner',
    ),
    new OA\Property(property: 'name', type: 'string', description: '插件名称'),
    new OA\Property(
      property: 'summary',
      type: 'string',
      description: '一句话简介',
    ),
    new OA\Property(
      property: 'description_md',
      type: 'string',
      description: '详情(Markdown)',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: draft/online/offline',
    ),
    new OA\Property(
      property: 'latest_version',
      type: 'string',
      description: '最新版本号',
    ),
    new OA\Property(
      property: 'doc_category_id',
      type: 'integer',
      description: '平台文档默认分类id,预留',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
    new OA\Property(property: 'features_count', type: 'integer'),
    new OA\Property(property: 'bindings_count', type: 'integer'),
  ],
  description: '插件列表及功能点、绑定码数量。',
)]
#[OA\Schema(
  schema: 'AdminClientListItem',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'client_id',
      type: 'string',
      description: '公开client_id',
    ),
    new OA\Property(property: 'name', type: 'string', description: '应用名称'),
    new OA\Property(
      property: 'type',
      type: 'string',
      description: '类型: third_party/plugin',
    ),
    new OA\Property(
      property: 'server_id',
      type: 'integer',
      description: 'plugin类应用归属空间id,空=平台级',
      nullable: true,
    ),
    new OA\Property(
      property: 'redirect_uris',
      type: 'array',
      items: new OA\Items(type: 'string'),
    ),
    new OA\Property(
      property: 'scopes',
      type: 'array',
      items: new OA\Items(type: 'string'),
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      description: '状态: active/suspended',
    ),
    new OA\Property(
      property: 'note',
      type: 'string',
      description: '对接备注,仅超管可见',
    ),
    new OA\Property(
      property: 'created_by',
      type: 'integer',
      description: '创建者用户id',
      nullable: true,
    ),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'updated_time',
      type: 'string',
      description: '更新时间',
      nullable: true,
    ),
    new OA\Property(
      property: 'server',
      ref: '#/components/schemas/AdminServerBrief',
      nullable: true,
    ),
  ],
  description: '应用列表及关联空间，不含密钥哈希。',
)]
#[OA\Schema(
  schema: 'AdminTagListItem',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer', description: '主键'),
    new OA\Property(
      property: 'name',
      type: 'string',
      description: '标签名,全局唯一',
    ),
    new OA\Property(property: 'note', type: 'string', description: '备注'),
    new OA\Property(
      property: 'created_time',
      type: 'string',
      description: '创建时间',
      nullable: true,
    ),
    new OA\Property(property: 'user_count', type: 'integer'),
  ],
  description: '标签列表及关联用户数量。',
)]
#[OA\Schema(
  schema: 'AdminId',
  type: 'object',
  properties: [new OA\Property(property: 'id', type: 'integer')],
  required: ['id'],
  description: '新建记录的 ID。',
)]
#[OA\Schema(
  schema: 'AdminVersion',
  type: 'object',
  properties: [new OA\Property(property: 'version', type: 'integer')],
  required: ['version'],
  description: '文档当前版本号。',
)]
#[OA\Schema(
  schema: 'AdminTagIds',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'tag_ids',
      type: 'array',
      items: new OA\Items(type: 'integer'),
    ),
  ],
  required: ['tag_ids'],
  description: '保存后有效且去重的用户标签 ID。',
)]
#[OA\Schema(
  schema: 'AdminClientCreated',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'client_id', type: 'string'),
    new OA\Property(
      property: 'client_secret',
      type: 'string',
      description: '明文密钥仅本次返回，请立即保存。',
    ),
  ],
  required: ['id', 'client_id', 'client_secret'],
  description: '注册应用结果，明文密钥仅本次返回。',
)]
#[OA\Schema(
  schema: 'AdminClientSecret',
  type: 'object',
  properties: [
    new OA\Property(property: 'client_id', type: 'string'),
    new OA\Property(
      property: 'client_secret',
      type: 'string',
      description: '明文密钥仅本次返回，请立即保存。',
    ),
  ],
  required: ['client_id', 'client_secret'],
  description: '重置应用密钥结果，旧应用会话全部撤销。',
)]
#[OA\Schema(
  schema: 'AdminServerDetail',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'server',
      ref: '#/components/schemas/AdminServerWithOwner',
    ),
    new OA\Property(
      property: 'members',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/AdminMemberWithUser'),
    ),
    new OA\Property(
      property: 'bindings',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/AdminBindingWithPlugin'),
    ),
  ],
  required: ['server', 'members', 'bindings'],
  description: '空间基本信息、成员及绑定码。',
)]
#[OA\Schema(
  schema: 'AdminUserDetail',
  type: 'object',
  properties: [
    new OA\Property(property: 'user', ref: '#/components/schemas/AdminUser'),
    new OA\Property(
      property: 'sessions',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/AdminAuthSession'),
    ),
    new OA\Property(
      property: 'servers',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/AdminMemberWithServer'),
    ),
    new OA\Property(
      property: 'tags',
      type: 'array',
      items: new OA\Items(
        type: 'object',
        properties: [
          new OA\Property(property: 'id', type: 'integer'),
          new OA\Property(property: 'name', type: 'string', nullable: true),
        ],
      ),
    ),
    new OA\Property(
      property: 'recent_logs',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/AdminAuditLog'),
    ),
  ],
  required: ['user', 'sessions', 'servers', 'tags', 'recent_logs'],
  description: '用户详情，含最近50条会话和20条审计日志。',
)]
#[OA\Schema(
  schema: 'AdminDashboard',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'users',
      type: 'object',
      properties: [
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'banned', type: 'integer'),
      ],
    ),
    new OA\Property(
      property: 'servers',
      type: 'object',
      properties: [
        new OA\Property(property: 'active', type: 'integer'),
        new OA\Property(property: 'suspended', type: 'integer'),
        new OA\Property(property: 'closed', type: 'integer'),
      ],
    ),
    new OA\Property(
      property: 'plugins',
      type: 'object',
      properties: [
        new OA\Property(property: 'online', type: 'integer'),
        new OA\Property(property: 'total', type: 'integer'),
      ],
    ),
    new OA\Property(
      property: 'bindings',
      type: 'object',
      properties: [
        new OA\Property(property: 'active', type: 'integer'),
        new OA\Property(property: 'revoked', type: 'integer'),
        new OA\Property(property: 'disabled', type: 'integer'),
        new OA\Property(property: 'stale_heartbeat', type: 'integer'),
      ],
    ),
    new OA\Property(
      property: 'recent_logs',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/AdminAuditLog'),
    ),
  ],
  description: '平台统计及最近10条审计日志；失联统计仅包括已锁定服务器 hash 的 active 绑定码。',
)]
#[OA\Schema(
  schema: 'AdminNotificationBatch',
  type: 'object',
  properties: [
    new OA\Property(property: 'batch_uuid', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(
      property: 'category',
      type: 'string',
      enum: ['announcement', 'personal_update', 'service_notice'],
    ),
    new OA\Property(
      property: 'scope',
      type: 'string',
      enum: ['community', 'personal'],
    ),
    new OA\Property(
      property: 'necessity',
      type: 'string',
      enum: ['optional', 'necessary'],
    ),
    new OA\Property(property: 'recipients', type: 'integer'),
    new OA\Property(property: 'unread', type: 'integer'),
    new OA\Property(property: 'expires_time', type: 'string', nullable: true),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['expired', 'active'],
    ),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
  ],
  description: '按批次聚合的通知记录。',
)]
#[OA\Schema(
  schema: 'AdminNotificationCreated',
  type: 'object',
  properties: [
    new OA\Property(property: 'batch_uuid', type: 'string', format: 'uuid'),
    new OA\Property(property: 'recipients', type: 'integer'),
  ],
  required: ['batch_uuid', 'recipients'],
  description: '发送后的通知批次和接收人数。',
)]
#[OA\Schema(
  schema: 'AdminAffected',
  type: 'object',
  properties: [new OA\Property(property: 'affected', type: 'integer')],
  required: ['affected'],
  description: '按批次撤回或删除的通知回执数。',
)]
#[OA\Schema(
  schema: 'AdminSsoRequest',
  type: 'object',
  properties: [
    new OA\Property(property: 'device_code', type: 'string'),
    new OA\Property(property: 'client_id', type: 'string'),
    new OA\Property(property: 'user_code', type: 'string'),
    new OA\Property(property: 'sso_request_id', type: 'string'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'user_id', type: 'integer', nullable: true),
    new OA\Property(
      property: 'ttl',
      type: 'integer',
      minimum: 0,
      description: '剩余有效秒数。',
    ),
    new OA\Property(
      property: 'created_ago',
      type: 'integer',
      minimum: 0,
      description: '创建至今秒数，按600秒有效期推算。',
    ),
    new OA\Property(property: 'client_name', type: 'string'),
    new OA\Property(property: 'username', type: 'string'),
  ],
  description: 'Redis 中仍存在的设备及 SSO 请求，不分页。',
)]
#[OA\Schema(
  schema: 'AdminFeedbackListItem',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
    new OA\Property(property: 'submission_key', type: 'string', format: 'uuid'),
    new OA\Property(
      property: 'area',
      type: 'string',
      enum: ['development', 'community'],
    ),
    new OA\Property(
      property: 'kind',
      type: 'string',
      enum: [
        'bug',
        'feature',
        'asset',
        'request',
        'content',
        'campaign',
        'suggestion',
        'complaint',
      ],
    ),
    new OA\Property(
      property: 'user',
      ref: '#/components/schemas/AdminUserBrief',
      nullable: true,
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['receiving', 'completed'],
    ),
    new OA\Property(
      property: 'admin_status',
      type: 'string',
      enum: ['pending', 'processing', 'resolved'],
    ),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
    new OA\Property(property: 'updated_time', type: 'string', nullable: true),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'attachment_count', type: 'integer'),
  ],
  description: '反馈工单列表。',
)]
#[OA\Schema(
  schema: 'AdminFeedbackAttachment',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ordinal', type: 'integer'),
    new OA\Property(property: 'original_name', type: 'string'),
    new OA\Property(property: 'content_type', type: 'string'),
    new OA\Property(property: 'byte_length', type: 'integer'),
    new OA\Property(property: 'sha256', type: 'string'),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['expected', 'uploaded'],
    ),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
  ],
  description: '反馈附件元数据，不包含存储路径和下载地址。',
)]
#[OA\Schema(
  schema: 'AdminFeedbackForm',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'kind',
      type: 'string',
      enum: [
        'bug',
        'feature',
        'asset',
        'request',
        'content',
        'campaign',
        'suggestion',
        'complaint',
      ],
    ),
    new OA\Property(
      property: 'title',
      type: 'string',
      minLength: 1,
      maxLength: 200,
    ),
  ],
  required: ['kind', 'title'],
  additionalProperties: true,
  description: '用户表单快照；后端只校验 kind/title，其余字段按原样保存。',
)]
#[OA\Schema(
  schema: 'AdminFeedbackDetail',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
    new OA\Property(property: 'submission_key', type: 'string', format: 'uuid'),
    new OA\Property(
      property: 'area',
      type: 'string',
      enum: ['development', 'community'],
    ),
    new OA\Property(
      property: 'kind',
      type: 'string',
      enum: [
        'bug',
        'feature',
        'asset',
        'request',
        'content',
        'campaign',
        'suggestion',
        'complaint',
      ],
    ),
    new OA\Property(
      property: 'user',
      ref: '#/components/schemas/AdminUserBrief',
      nullable: true,
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['receiving', 'completed'],
    ),
    new OA\Property(
      property: 'admin_status',
      type: 'string',
      enum: ['pending', 'processing', 'resolved'],
    ),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
    new OA\Property(property: 'updated_time', type: 'string', nullable: true),
    new OA\Property(
      property: 'form',
      ref: '#/components/schemas/AdminFeedbackForm',
    ),
    new OA\Property(property: 'admin_note', type: 'string', nullable: true),
    new OA\Property(
      property: 'attachments',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/AdminFeedbackAttachment'),
    ),
  ],
  description: '工单表单快照、管理员备注和附件元数据。',
)]
class AdminSchemas {}
