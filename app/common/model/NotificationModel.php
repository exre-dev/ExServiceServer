<?php

namespace app\common\model;

/**
 * @property integer $id 主键(兼作投递序号 sequence)
 * @property string $uuid 回执UUID(v4), 对外 receiptId
 * @property integer $user_id 归属用户id
 * @property string $scope 范围: community/personal
 * @property string $category 类别: announcement/personal_update/service_notice
 * @property string $necessity 必要性: optional/necessary
 * @property string $title 标题
 * @property string $summary_text 列表摘要纯文本
 * @property string|null $body_html 正文安全HTML
 * @property string|null $body_text 正文纯文本回退
 * @property string|null $actions 动作数组JSON
 * @property string|null $expires_time 过期时间,空=不过期
 * @property integer|null $read_at 已读时间戳(unix秒),空=未读
 * @property integer|null $cleared_at 清理时间戳(unix秒)
 * @property integer $state_revision 读状态修订号
 * @property integer $clear_revision 清理修订号
 * @property string $created_time 创建时间
 * @property string $updated_time 更新时间
 */
class NotificationModel extends BaseModel
{
    protected $table = 'app_notifications';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    protected $fillable = [
        "uuid", "user_id", "scope", "category", "necessity",
        "title", "summary_text", "body_html", "body_text", "actions", "expires_time",
    ];

    /** actions JSONB 自动转数组 */
    protected $casts = [
        'actions' => 'array',
    ];
}
