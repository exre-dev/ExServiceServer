<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 文档管理和浏览的数据结构。 */
#[OA\Schema(
  schema: 'ContentDocumentSummary',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(
      property: 'owner_type',
      type: 'string',
      enum: ['platform', 'server'],
    ),
    new OA\Property(property: 'server_id', type: 'integer', nullable: true),
    new OA\Property(property: 'category_id', type: 'integer', nullable: true),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'slug', type: 'string'),
    new OA\Property(
      property: 'visibility',
      type: 'string',
      enum: ['public', 'login', 'members', 'roles', 'servers', 'tags'],
    ),
    new OA\Property(
      property: 'visible_roles',
      type: 'array',
      items: new OA\Items(type: 'string', enum: ['owner', 'admin', 'member']),
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
    new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'updated_time', type: 'string', nullable: true),
  ],
)]
#[OA\Schema(
  schema: 'ContentDocument',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(
      property: 'owner_type',
      type: 'string',
      enum: ['platform', 'server'],
    ),
    new OA\Property(property: 'server_id', type: 'integer', nullable: true),
    new OA\Property(property: 'category_id', type: 'integer', nullable: true),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'slug', type: 'string'),
    new OA\Property(
      property: 'visibility',
      type: 'string',
      enum: ['public', 'login', 'members', 'roles', 'servers', 'tags'],
    ),
    new OA\Property(
      property: 'visible_roles',
      type: 'array',
      items: new OA\Items(type: 'string', enum: ['owner', 'admin', 'member']),
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
    new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'updated_time', type: 'string', nullable: true),
    new OA\Property(property: 'content_md', type: 'string'),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['draft', 'published'],
    ),
    new OA\Property(
      property: 'author_user_id',
      type: 'integer',
      nullable: true,
    ),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
  ],
)]
#[OA\Schema(
  schema: 'ContentCategory',
  type: 'object',
  properties: [
    new OA\Property(property: 'id', type: 'integer'),
    new OA\Property(
      property: 'owner_type',
      type: 'string',
      enum: ['platform', 'server'],
    ),
    new OA\Property(property: 'server_id', type: 'integer', nullable: true),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'sort', type: 'integer'),
    new OA\Property(property: 'created_time', type: 'string', nullable: true),
    new OA\Property(property: 'updated_time', type: 'string', nullable: true),
  ],
)]
#[OA\Schema(
  schema: 'ContentBrowseData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ContentDocumentSummary'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ContentManagedDocsData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ContentDocument'),
    ),
    new OA\Property(property: 'total', type: 'integer'),
  ],
)]
#[OA\Schema(
  schema: 'ContentCategoriesData',
  type: 'object',
  properties: [
    new OA\Property(
      property: 'list',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/ContentCategory'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ContentServerDocCreateInput',
  type: 'object',
  required: ['title'],
  description: 'visibility 默认 members，content_md 默认空；归属与作者由服务端设置。roles 至少一个有效角色，否则 400；非 roles 的 visible_roles 存 null。',
  properties: [
    new OA\Property(
      property: 'title',
      type: 'string',
      description: '创建时 trim 后必填；更新时为空则忽略',
    ),
    new OA\Property(
      property: 'slug',
      type: 'string',
      description: '转小写并 trim；仅接受 ^[a-z0-9][a-z0-9-]{0,60}$，无效或冲突自动生成唯一 doc- 标识',
    ),
    new OA\Property(property: 'content_md', type: 'string'),
    new OA\Property(
      property: 'category_id',
      type: 'integer',
      description: '0 转为 null；当前控制器不校验分类归属',
    ),
    new OA\Property(
      property: 'visibility',
      type: 'string',
      enum: ['public', 'members', 'roles'],
    ),
    new OA\Property(
      property: 'visible_roles',
      type: 'array',
      items: new OA\Items(type: 'string', enum: ['owner', 'admin', 'member']),
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['draft', 'published'],
      default: 'draft',
      description: '无效状态回退 draft',
    ),
  ],
)]
#[OA\Schema(
  schema: 'ContentServerDocUpdateInput',
  type: 'object',
  description: '仅更新提供的字段；status 不在本接口更新。有效编辑 version +1；角色数组仅保留 owner/admin/member，roles 至少一个角色。',
  properties: [
    new OA\Property(
      property: 'title',
      type: 'string',
      description: '创建时 trim 后必填；更新时为空则忽略',
    ),
    new OA\Property(
      property: 'slug',
      type: 'string',
      description: '转小写并 trim；仅接受 ^[a-z0-9][a-z0-9-]{0,60}$，无效或冲突自动生成唯一 doc- 标识',
    ),
    new OA\Property(property: 'content_md', type: 'string'),
    new OA\Property(
      property: 'category_id',
      type: 'integer',
      description: '0 转为 null；当前控制器不校验分类归属',
    ),
    new OA\Property(
      property: 'visibility',
      type: 'string',
      enum: ['public', 'members', 'roles'],
    ),
    new OA\Property(
      property: 'visible_roles',
      type: 'array',
      items: new OA\Items(type: 'string', enum: ['owner', 'admin', 'member']),
    ),
  ],
)]
#[OA\Schema(
  schema: 'ContentDocStatusInput',
  type: 'object',
  required: ['status'],
  properties: [
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['draft', 'published'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'ContentCategoryInput',
  type: 'object',
  required: ['name'],
  properties: [
    new OA\Property(
      property: 'name',
      type: 'string',
      description: 'trim 后非空',
    ),
    new OA\Property(property: 'sort', type: 'integer', default: 0),
  ],
)]
class ContentSchemas {}
