<?php

namespace app\common\model;

use support\Model;

/**
 * 用户↔应用授权表
 * @property integer $id 主键
 * @property integer $user_id 授权用户id
 * @property string $client_id 被授权应用client_id
 * @property array $scopes 实际授予的scope列表
 * @property string $status 状态: active/revoked
 * @property string|null $granted_time 授权时间
 * @property string|null $revoked_time 撤销时间
 * @property string $revoked_by 撤销方: ''/user/server_admin/platform
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class OAuthGrantModel extends BaseModel
{
    protected $table = 'app_oauth_grants';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    protected $casts = [
        'scopes' => 'array',
    ];
}
