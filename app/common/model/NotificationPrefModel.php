<?php

namespace app\common\model;

/**
 * @property integer $user_id 用户id(主键)
 * @property bool $announcement announcement 类别投影开关
 * @property bool $personal_update personal_update 类别投影开关
 * @property integer $revision 偏好修订号
 * @property string $updated_time 更新时间
 */
class NotificationPrefModel extends BaseModel
{
    protected $table = 'app_notification_prefs';

    const CREATED_AT = null;
    const UPDATED_AT = "updated_time";

    protected $primaryKey = 'user_id';

    protected $fillable = [
        "user_id", "announcement", "personal_update", "revision",
    ];
}
