<?php

namespace app\common\model;

use support\Model;

/**
 * 服务器空间表(B2端)
 * @property integer $id 空间唯一标识
 * @property string $name 空间名称
 * @property string $slug 空间短标识,全局唯一
 * @property string $description 空间介绍
 * @property string $logo 空间图标地址
 * @property integer $owner_user_id Owner用户id
 * @property string $contact_qq 联系QQ
 * @property string $status 状态: active/suspended/closed
 * @property string $note 平台备注,仅超管可见
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class ServerAccountModel extends BaseModel
{
    protected $table = 'app_server_accounts';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    /** 成员列表 */
    public function members()
    {
        return $this->hasMany(ServerMemberModel::class, 'server_id', 'id');
    }

    /** 空间插件绑定码 */
    public function bindings()
    {
        return $this->hasMany(PluginBindingModel::class, 'server_id', 'id');
    }

    /** Owner 用户 */
    public function owner()
    {
        return $this->belongsTo(UserModel::class, 'owner_user_id', 'id');
    }
}
