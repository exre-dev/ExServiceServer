<?php

namespace app\common\model;

/**
 * @property integer $id 主键
 * @property string $uuid 工单UUID(对外 id)
 * @property string $submission_key 客户端生成的恢复键
 * @property string $area 入口: development/community
 * @property string $kind 反馈类型
 * @property integer $user_id 提交用户id
 * @property array $form 表单快照JSON
 * @property string $status 用户侧状态: receiving/completed
 * @property string $admin_status 管理侧状态: pending/processing/resolved
 * @property string|null $admin_note 管理员备注
 * @property integer $revision 修订号
 * @property string|null $receive_expires_at 收取截止时间
 * @property string $created_time 创建时间
 * @property string $updated_time 更新时间
 */
class FeedbackModel extends BaseModel
{
    protected $table = 'app_feedback_submissions';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    protected $fillable = [
        "uuid", "submission_key", "area", "kind", "user_id", "form", "status",
        "admin_status", "admin_note", "revision", "receive_expires_at",
    ];

    protected $casts = [
        'form' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }
}
