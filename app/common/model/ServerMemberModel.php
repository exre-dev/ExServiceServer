<?php

namespace app\common\model;

use support\Model;

/**
 * 空间成员表(用户↔空间多对多)
 * @property integer $id 主键
 * @property integer $server_id 空间id
 * @property integer $user_id 用户id
 * @property string $role_key 空间角色: owner/admin/member
 * @property string $status 状态: active/banned/left
 * @property string $source 入空间方式: grant/manual/bind
 * @property string|null $joined_time 加入时间
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class ServerMemberModel extends BaseModel
{
    protected $table = 'app_server_members';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    /** 所属空间 */
    public function server()
    {
        return $this->belongsTo(ServerAccountModel::class, 'server_id', 'id');
    }

    /** 成员用户 */
    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }
}
