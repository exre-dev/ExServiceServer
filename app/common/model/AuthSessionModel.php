<?php

namespace app\common\model;

use support\Model;

/**
 * 登录会话表(多设备/多平台)
 * @property integer $id 会话id,即JWT中的sid
 * @property integer $user_id 归属用户id
 * @property string $login_type 登录方式: password/oauth
 * @property string|null $client_id OAuth时关联的应用client_id
 * @property string $device_name 设备名(UA自动识别)
 * @property string $platform 平台: web/fivem/third_party
 * @property string $ip 登录来源IP
 * @property string $ua_hash UA摘要
 * @property string $scope OAuth时授予的scope
 * @property string $status 状态: active/revoked/expired
 * @property string|null $created_time 登录时间
 * @property string|null $last_used_time 最近使用时间
 * @property string|null $expires_time 会话过期时间
 * @property string|null $updated_time 更新时间
 */
class AuthSessionModel extends BaseModel
{
    protected $table = 'app_auth_sessions';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    /** 归属用户 */
    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }
}
