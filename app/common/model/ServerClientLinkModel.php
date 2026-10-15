<?php

namespace app\common\model;

use support\Model;

/**
 * 空间↔应用关联表
 * @property integer $id 主键
 * @property integer $server_id 空间id
 * @property string $client_id 应用client_id
 * @property string $status 状态: active/disconnected
 * @property string|null $linked_time 关联时间
 * @property string|null $disconnected_time 断开时间
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class ServerClientLinkModel extends BaseModel
{
    protected $table = 'app_server_client_links';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";
}
