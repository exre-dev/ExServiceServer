<?php

namespace app\common\model;

use support\Model;

/**
 * 用户游戏标识表
 * @property integer $id 主键
 * @property integer $user_id 归属用户id
 * @property string $ident_type 标识类型: fivem_license/steam/discord/qq
 * @property string $ident_value 标识值,如 license:xxxx
 * @property integer|null $bound_server_id 在哪个空间完成绑定,追溯用
 * @property string|null $created_time 绑定时间
 */
class UserIdentModel extends BaseModel
{
    protected $table = 'app_user_idents';

    const CREATED_AT = "created_time";
    const UPDATED_AT = null;

    /** 归属用户 */
    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id', 'id');
    }
}
