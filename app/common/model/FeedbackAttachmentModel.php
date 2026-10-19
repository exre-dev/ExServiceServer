<?php

namespace app\common\model;

/**
 * @property integer $id 主键
 * @property string $uuid 附件UUID(对外 attachmentId)
 * @property integer $submission_id 所属工单id
 * @property integer $ordinal 顺序(0-4)
 * @property string $original_name 原始文件名
 * @property string $content_type 内容类型
 * @property integer $byte_length 字节数
 * @property string $sha256 SHA-256
 * @property string $status expected/uploaded
 * @property string|null $storage_key 落盘键(public/uploads 下相对路径)
 * @property string $created_time 创建时间
 * @property string $updated_time 更新时间
 */
class FeedbackAttachmentModel extends BaseModel
{
    protected $table = 'app_feedback_attachments';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    protected $fillable = [
        "uuid", "submission_id", "ordinal", "original_name", "content_type",
        "byte_length", "sha256", "status", "storage_key",
    ];
}
