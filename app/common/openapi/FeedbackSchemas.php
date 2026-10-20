<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 实际 PHP 请求与响应结构。 */
#[OA\Schema(
  schema: 'FeedbackForm',
  type: 'object',
  required: ['kind', 'title'],
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
  additionalProperties: true,
  description: 'PHP 校验 kind/title 并完整保存其他表单字段；JSON 编码后超过 131072 字节返回 413',
)]
#[OA\Schema(
  schema: 'FeedbackAttachmentDeclaration',
  type: 'object',
  required: ['originalName', 'contentType', 'byteLength', 'sha256'],
  properties: [
    new OA\Property(
      property: 'id',
      type: 'string',
      format: 'uuid',
      description: '可选 UUID v4；缺失或无效时由服务端生成',
    ),
    new OA\Property(
      property: 'originalName',
      type: 'string',
      description: '存储时截断为 510 字符',
    ),
    new OA\Property(
      property: 'contentType',
      type: 'string',
      description: '原样声明，存储时截断为 127 字符',
    ),
    new OA\Property(property: 'byteLength', type: 'integer'),
    new OA\Property(
      property: 'sha256',
      type: 'string',
      description: '原始字节 SHA-256 小写摘要',
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackPrepareRequest',
  type: 'object',
  required: ['submissionKey', 'form', 'attachments'],
  properties: [
    new OA\Property(
      property: 'submissionKey',
      type: 'string',
      format: 'uuid',
      description: '客户端 UUID v4；同一用户与键重放返回原快照',
    ),
    new OA\Property(property: 'form', ref: '#/components/schemas/FeedbackForm'),
    new OA\Property(
      property: 'attachments',
      type: 'array',
      items: new OA\Items(
        ref: '#/components/schemas/FeedbackAttachmentDeclaration',
      ),
      maxItems: 5,
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackAttachment',
  type: 'object',
  required: [
    'id',
    'ordinal',
    'originalName',
    'contentType',
    'byteLength',
    'sha256',
    'status',
    'remoteAttachmentId',
  ],
  properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ordinal', type: 'integer'),
    new OA\Property(property: 'originalName', type: 'string'),
    new OA\Property(property: 'contentType', type: 'string'),
    new OA\Property(property: 'byteLength', type: 'integer'),
    new OA\Property(property: 'sha256', type: 'string'),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['expected', 'uploaded'],
    ),
    new OA\Property(
      property: 'remoteAttachmentId',
      type: 'string',
      nullable: true,
      enum: [null],
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackSnapshotData',
  type: 'object',
  required: [
    'id',
    'submissionKey',
    'revision',
    'form',
    'createdAt',
    'receiveExpiresAt',
    'status',
    'nextAction',
    'taskId',
    'taskUrl',
    'failure',
    'attachments',
  ],
  properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'submissionKey', type: 'string', format: 'uuid'),
    new OA\Property(property: 'revision', type: 'integer'),
    new OA\Property(property: 'form', ref: '#/components/schemas/FeedbackForm'),
    new OA\Property(
      property: 'createdAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
    new OA\Property(
      property: 'receiveExpiresAt',
      type: 'integer',
      format: 'int64',
      description: 'Unix 时间戳（秒）',
    ),
    new OA\Property(
      property: 'status',
      type: 'string',
      enum: ['receiving', 'completed'],
    ),
    new OA\Property(
      property: 'nextAction',
      type: 'string',
      enum: ['upload', 'continue', 'done'],
    ),
    new OA\Property(
      property: 'taskId',
      type: 'string',
      nullable: true,
      enum: [null],
    ),
    new OA\Property(
      property: 'taskUrl',
      type: 'string',
      nullable: true,
      enum: [null],
    ),
    new OA\Property(
      property: 'failure',
      type: 'string',
      nullable: true,
      enum: [null],
    ),
    new OA\Property(
      property: 'attachments',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/FeedbackAttachment'),
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackSnapshotResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/FeedbackSnapshotData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackAttachmentUploadRequest',
  type: 'object',
  required: ['file'],
  properties: [
    new OA\Property(
      property: 'file',
      type: 'string',
      format: 'binary',
      description: '最多 20MiB；字节数及 SHA-256 须与声明完全一致',
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackAdvanceRequest',
  type: 'object',
  required: ['expectedRevision', 'intent'],
  properties: [
    new OA\Property(property: 'expectedRevision', type: 'integer'),
    new OA\Property(
      property: 'intent',
      type: 'string',
      enum: ['continue', 'retry'],
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackKindOption',
  type: 'object',
  required: ['kind', 'available', 'reason'],
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
    new OA\Property(property: 'available', type: 'boolean'),
    new OA\Property(
      property: 'reason',
      type: 'string',
      nullable: true,
      enum: [null],
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackActionOption',
  type: 'object',
  required: ['action', 'available', 'reason'],
  properties: [
    new OA\Property(
      property: 'action',
      type: 'string',
      enum: ['add', 'modify', 'delete', 'other'],
    ),
    new OA\Property(property: 'available', type: 'boolean'),
    new OA\Property(
      property: 'reason',
      type: 'string',
      nullable: true,
      enum: [null],
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackLimits',
  type: 'object',
  required: [
    'titleCodePoints',
    'userContentBytes',
    'fileBytes',
    'files',
    'totalFileBytes',
    'largeFileInfoBytes',
  ],
  properties: [
    new OA\Property(property: 'titleCodePoints', type: 'integer', enum: [200]),
    new OA\Property(
      property: 'userContentBytes',
      type: 'integer',
      enum: [65536],
    ),
    new OA\Property(property: 'fileBytes', type: 'integer', enum: [20971520]),
    new OA\Property(property: 'files', type: 'integer', enum: [5]),
    new OA\Property(
      property: 'totalFileBytes',
      type: 'integer',
      enum: [104857600],
    ),
    new OA\Property(
      property: 'largeFileInfoBytes',
      type: 'integer',
      enum: [65536],
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackOptionsData',
  type: 'object',
  required: ['available', 'reason', 'fields', 'kinds', 'actions', 'limits'],
  properties: [
    new OA\Property(property: 'available', type: 'boolean'),
    new OA\Property(
      property: 'reason',
      type: 'string',
      nullable: true,
      enum: [null],
    ),
    new OA\Property(
      property: 'fields',
      type: 'array',
      items: new OA\Items(type: 'string'),
      maxItems: 0,
    ),
    new OA\Property(
      property: 'kinds',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/FeedbackKindOption'),
    ),
    new OA\Property(
      property: 'actions',
      type: 'array',
      items: new OA\Items(ref: '#/components/schemas/FeedbackActionOption'),
    ),
    new OA\Property(
      property: 'limits',
      ref: '#/components/schemas/FeedbackLimits',
    ),
  ],
)]
#[OA\Schema(
  schema: 'FeedbackOptionsResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/FeedbackOptionsData',
    ),
  ],
)]
final class FeedbackSchemas {}
