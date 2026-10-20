<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 实际 PHP 请求与响应结构。 */
#[OA\Schema(
  schema: 'NotificationItem',
  type: 'object',
  required: [
    'receiptId',
    'title',
    'scope',
    'category',
    'necessity',
    'sequence',
    'deliveredAt',
    'readAt',
    'summaryText',
    'expired',
    'stateRevision',
  ],
  properties: [
    new OA\Property(property: 'receiptId', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(
      property: 'scope',
      type: 'string',
      enum: ['community', 'personal'],
    ),
    new OA\Property(
      property: 'category',
      type: 'string',
      enum: ['announcement', 'personal_update', 'service_notice'],
    ),
    new OA\Property(
      property: 'necessity',
      type: 'string',
      enum: ['optional', 'necessary'],
    ),
    new OA\Property(property: 'sequence', type: 'integer'),
    new OA\Property(
      property: 'deliveredAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
    new OA\Property(
      property: 'readAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
      nullable: true,
    ),
    new OA\Property(property: 'summaryText', type: 'string'),
    new OA\Property(property: 'expired', type: 'boolean'),
    new OA\Property(property: 'stateRevision', type: 'integer'),
  ],
)]
#[OA\Schema(
  schema: 'NotificationContent',
  type: 'object',
  nullable: true,
  required: ['format', 'schemaVersion', 'policyVersion', 'html', 'text'],
  properties: [
    new OA\Property(property: 'format', type: 'string', enum: ['html']),
    new OA\Property(property: 'schemaVersion', type: 'integer', enum: [1]),
    new OA\Property(
      property: 'policyVersion',
      type: 'string',
      enum: ['notification-html-v1'],
    ),
    new OA\Property(property: 'html', type: 'string'),
    new OA\Property(property: 'text', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'NotificationNavigationTarget',
  type: 'object',
  nullable: true,
  required: ['kind', 'path'],
  properties: [
    new OA\Property(property: 'kind', type: 'string', enum: ['internal']),
    new OA\Property(property: 'path', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'NotificationAction',
  type: 'object',
  required: [
    'actionId',
    'kind',
    'label',
    'emphasis',
    'enabled',
    'unavailableReason',
    'navigationTarget',
  ],
  properties: [
    new OA\Property(property: 'actionId', type: 'string'),
    new OA\Property(property: 'kind', type: 'string'),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'emphasis', type: 'string'),
    new OA\Property(property: 'enabled', type: 'boolean'),
    new OA\Property(
      property: 'unavailableReason',
      type: 'string',
      nullable: true,
      enum: ['expired', 'unsupported', null],
    ),
    new OA\Property(
      property: 'navigationTarget',
      ref: '#/components/schemas/NotificationNavigationTarget',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationDetailData',
  type: 'object',
  required: [
    'receiptId',
    'title',
    'scope',
    'category',
    'necessity',
    'sequence',
    'deliveredAt',
    'readAt',
    'clearedAt',
    'lifecycle',
    'stateRevision',
    'clearRevision',
    'contentAvailable',
    'content',
    'contentFallbackText',
    'actions',
    'actionsAvailable',
    'serverTime',
  ],
  properties: [
    new OA\Property(property: 'receiptId', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(
      property: 'scope',
      type: 'string',
      enum: ['community', 'personal'],
    ),
    new OA\Property(
      property: 'category',
      type: 'string',
      enum: ['announcement', 'personal_update', 'service_notice'],
    ),
    new OA\Property(
      property: 'necessity',
      type: 'string',
      enum: ['optional', 'necessary'],
    ),
    new OA\Property(property: 'sequence', type: 'integer'),
    new OA\Property(
      property: 'deliveredAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
    new OA\Property(
      property: 'readAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
      nullable: true,
    ),
    new OA\Property(
      property: 'clearedAt',
      type: 'integer',
      nullable: true,
      enum: [null],
    ),
    new OA\Property(
      property: 'lifecycle',
      type: 'string',
      enum: ['active', 'expired'],
    ),
    new OA\Property(property: 'stateRevision', type: 'integer'),
    new OA\Property(property: 'clearRevision', type: 'integer'),
    new OA\Property(property: 'contentAvailable', type: 'boolean'),
    new OA\Property(
      property: 'content',
      ref: '#/components/schemas/NotificationContent',
    ),
    new OA\Property(
      property: 'contentFallbackText',
      type: 'string',
      nullable: true,
    ),
    new OA\Property(
      property: 'actions',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/NotificationAction'),
    ),
    new OA\Property(property: 'actionsAvailable', type: 'boolean'),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationDetailResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/NotificationDetailData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationListData',
  type: 'object',
  required: [
    'items',
    'nextCursor',
    'unreadCount',
    'upperSequence',
    'readThroughToken',
    'serverTime',
  ],
  properties: [
    new OA\Property(
      property: 'items',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/NotificationItem'),
    ),
    new OA\Property(property: 'nextCursor', type: 'string', nullable: true),
    new OA\Property(property: 'unreadCount', type: 'integer'),
    new OA\Property(property: 'upperSequence', type: 'integer'),
    new OA\Property(property: 'readThroughToken', type: 'string'),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationListResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/NotificationListData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationSummaryData',
  type: 'object',
  required: [
    'unreadCount',
    'stateVersion',
    'upperSequence',
    'readThroughToken',
    'serverTime',
  ],
  properties: [
    new OA\Property(property: 'unreadCount', type: 'integer'),
    new OA\Property(property: 'stateVersion', type: 'integer'),
    new OA\Property(property: 'upperSequence', type: 'integer'),
    new OA\Property(
      property: 'readThroughToken',
      type: 'string',
      description: '绑定用户与上界，十分钟有效',
    ),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationSummaryResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/NotificationSummaryData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationStateRequest',
  type: 'object',
  required: ['read', 'expectedRevision'],
  properties: [
    new OA\Property(property: 'read', type: 'boolean'),
    new OA\Property(property: 'expectedRevision', type: 'integer'),
  ],
)]
#[OA\Schema(
  schema: 'NotificationStateData',
  type: 'object',
  required: ['receiptId', 'read', 'stateRevision', 'unreadCount', 'serverTime'],
  properties: [
    new OA\Property(property: 'receiptId', type: 'string'),
    new OA\Property(property: 'read', type: 'boolean'),
    new OA\Property(property: 'stateRevision', type: 'integer'),
    new OA\Property(property: 'unreadCount', type: 'integer'),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationStateResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/NotificationStateData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationReadAllRequest',
  type: 'object',
  required: ['requestId', 'readThroughToken'],
  properties: [
    new OA\Property(
      property: 'requestId',
      type: 'string',
      description: '十分钟幂等结果键；全部已读与清理共用命名空间',
    ),
    new OA\Property(property: 'readThroughToken', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'NotificationReadAllData',
  type: 'object',
  required: [
    'requestId',
    'affectedCount',
    'watermarkSequence',
    'unreadCount',
    'replayed',
    'serverTime',
  ],
  properties: [
    new OA\Property(property: 'requestId', type: 'string'),
    new OA\Property(property: 'affectedCount', type: 'integer'),
    new OA\Property(property: 'watermarkSequence', type: 'integer'),
    new OA\Property(property: 'unreadCount', type: 'integer'),
    new OA\Property(property: 'replayed', type: 'boolean'),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationReadAllResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/NotificationReadAllData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationClearPreviewData',
  type: 'object',
  required: [
    'batchId',
    'itemCount',
    'upperSequence',
    'clearSnapshotToken',
    'expiresAt',
    'serverTime',
  ],
  properties: [
    new OA\Property(property: 'batchId', type: 'string', format: 'uuid'),
    new OA\Property(property: 'itemCount', type: 'integer'),
    new OA\Property(property: 'upperSequence', type: 'integer'),
    new OA\Property(
      property: 'clearSnapshotToken',
      type: 'string',
      description: '绑定用户、批次及上界，十分钟有效',
    ),
    new OA\Property(
      property: 'expiresAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationClearPreviewResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/NotificationClearPreviewData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationClearAllRequest',
  type: 'object',
  required: ['requestId', 'clearSnapshotToken'],
  properties: [
    new OA\Property(
      property: 'requestId',
      type: 'string',
      description: '十分钟幂等结果键；全部已读与清理共用命名空间',
    ),
    new OA\Property(property: 'clearSnapshotToken', type: 'string'),
  ],
)]
#[OA\Schema(
  schema: 'NotificationClearAllData',
  type: 'object',
  required: [
    'requestId',
    'batchId',
    'affectedCount',
    'skippedCount',
    'unreadCount',
    'replayed',
    'serverTime',
  ],
  properties: [
    new OA\Property(property: 'requestId', type: 'string'),
    new OA\Property(property: 'batchId', type: 'string'),
    new OA\Property(property: 'affectedCount', type: 'integer'),
    new OA\Property(property: 'skippedCount', type: 'integer'),
    new OA\Property(property: 'unreadCount', type: 'integer'),
    new OA\Property(property: 'replayed', type: 'boolean'),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（毫秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationClearAllResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/NotificationClearAllData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationOptionalCategories',
  type: 'object',
  required: ['announcement', 'personal_update'],
  properties: [
    new OA\Property(property: 'announcement', type: 'boolean'),
    new OA\Property(property: 'personal_update', type: 'boolean'),
  ],
)]
#[OA\Schema(
  schema: 'NotificationPreferencesRequest',
  type: 'object',
  required: ['expectedRevision', 'optionalCategories'],
  properties: [
    new OA\Property(property: 'expectedRevision', type: 'integer'),
    new OA\Property(
      property: 'optionalCategories',
      ref: '#/components/schemas/NotificationOptionalCategories',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationPreferencesData',
  type: 'object',
  required: [
    'revision',
    'optionalCategories',
    'necessaryCategories',
    'serverTime',
  ],
  properties: [
    new OA\Property(property: 'revision', type: 'integer'),
    new OA\Property(
      property: 'optionalCategories',
      ref: '#/components/schemas/NotificationOptionalCategories',
    ),
    new OA\Property(
      property: 'necessaryCategories',
      type: 'array',
      items: new OA\Items(type: 'string'),
      description: '固定包含 service_notice，不可关闭',
    ),
    new OA\Property(
      property: 'serverTime',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
  ],
)]
#[OA\Schema(
  schema: 'NotificationPreferencesResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/NotificationPreferencesData',
    ),
  ],
)]
final class NotificationSchemas {}
