<?php

namespace app\common\model;

use support\Model;

/**
 * 成员插件权限表
 * @property integer $id 主键
 * @property integer $server_id 空间id
 * @property integer $user_id 用户id
 * @property integer $plugin_id 插件id
 * @property boolean $allowed 是否允许使用
 * @property array|null $features 功能覆盖,空=继承绑定码全量
 * @property string $note 管理员备注
 * @property integer|null $updated_by 最后修改人
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class MemberPluginPermModel extends BaseModel
{
    protected $table = 'app_member_plugin_perms';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    protected $casts = [
        'features' => 'array',
    ];
}
