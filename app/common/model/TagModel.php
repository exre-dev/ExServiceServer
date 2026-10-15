<?php

namespace app\common\model;

use support\Model;

/**
 * 用户标签字典
 * @property integer $id 主键
 * @property string $name 标签名,全局唯一
 * @property string $note 备注
 * @property string|null $created_time 创建时间
 */
class TagModel extends Model
{
    protected $table = 'app_tags';

    const CREATED_AT = "created_time";
    const UPDATED_AT = null;

    /** 拥有该标签的用户 */
    public function users()
    {
        return $this->belongsToMany(UserModel::class, 'app_user_tags', 'tag_id', 'user_id');
    }
}
