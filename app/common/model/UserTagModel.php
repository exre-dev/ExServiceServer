<?php

namespace app\common\model;

use support\Model;

/**
 * 用户↔标签关联
 * @property integer $id 主键
 * @property integer $user_id 用户id
 * @property integer $tag_id 标签id
 * @property string|null $created_time 打标时间
 */
class UserTagModel extends Model
{
    protected $table = 'app_user_tags';

    const CREATED_AT = "created_time";
    const UPDATED_AT = null;

    /** 标签 */
    public function tag()
    {
        return $this->belongsTo(TagModel::class, 'tag_id', 'id');
    }
}
