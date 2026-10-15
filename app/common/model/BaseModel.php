<?php

namespace app\common\model;

use DateTimeInterface;
use support\Model;

/**
 * 模型基类：统一时间戳格式（存储与序列化均为 Y-m-d H:i:s 字符串）
 *
 * 覆写 serializeDate 是因为本版本 Eloquent 默认用 Carbon::toJSON()
 * 输出 ISO8601（带 T/Z 后缀），不符合项目约定。
 */
class BaseModel extends Model
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
