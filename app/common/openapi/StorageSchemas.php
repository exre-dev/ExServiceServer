<?php

namespace app\common\openapi;

use OpenApi\Attributes as OA;

/** 实际 PHP 请求与响应结构。 */
#[OA\Schema(
  schema: 'StoragePrepareRequest',
  type: 'object',
  required: ['key', 'size', 'contentType'],
  properties: [
    new OA\Property(
      property: 'key',
      type: 'string',
      minLength: 1,
      maxLength: 240,
      pattern: '^[a-z0-9](?:[a-z0-9._-]*[a-z0-9_-])?(?:/[a-z0-9](?:[a-z0-9._-]*[a-z0-9_-])?)*$',
      description: '小写分段路径，禁止 Windows 保留设备名',
    ),
    new OA\Property(
      property: 'size',
      type: 'integer',
      description: '超过 20MiB 返回 413',
    ),
    new OA\Property(
      property: 'contentType',
      type: 'string',
      maxLength: 127,
      description: '合法 type/subtype，不接受参数',
    ),
    new OA\Property(property: 'medium', type: 'string', enum: ['file', 'cos']),
  ],
)]
#[OA\Schema(
  schema: 'StoragePrepareData',
  type: 'object',
  required: ['medium', 'maxBytes'],
  properties: [
    new OA\Property(property: 'medium', type: 'string', enum: ['file']),
    new OA\Property(property: 'maxBytes', type: 'integer', enum: [20971520]),
  ],
)]
#[OA\Schema(
  schema: 'StoragePrepareResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/StoragePrepareData',
    ),
  ],
)]
#[OA\Schema(
  schema: 'StorageUploadRequest',
  type: 'object',
  required: ['key', 'data'],
  properties: [
    new OA\Property(
      property: 'key',
      type: 'string',
      minLength: 1,
      maxLength: 240,
      pattern: '^[a-z0-9](?:[a-z0-9._-]*[a-z0-9_-])?(?:/[a-z0-9](?:[a-z0-9._-]*[a-z0-9_-])?)*$',
      description: '小写分段路径，禁止 Windows 保留设备名',
    ),
    new OA\Property(
      property: 'data',
      type: 'string',
      format: 'binary',
      description: '文件字节，最多 20MiB',
    ),
    new OA\Property(property: 'medium', type: 'string', enum: ['file']),
    new OA\Property(
      property: 'contentType',
      type: 'string',
      maxLength: 127,
      description: '合法 type/subtype，不接受参数',
    ),
  ],
)]
#[OA\Schema(
  schema: 'StorageUploadData',
  type: 'object',
  required: ['url'],
  properties: [new OA\Property(property: 'url', type: 'string', format: 'uri')],
)]
#[OA\Schema(
  schema: 'StorageUploadResponse',
  type: 'object',
  required: ['msg', 'data'],
  properties: [
    new OA\Property(property: 'msg', type: 'string'),
    new OA\Property(
      property: 'data',
      ref: '#/components/schemas/StorageUploadData',
    ),
  ],
)]
final class StorageSchemas {}
