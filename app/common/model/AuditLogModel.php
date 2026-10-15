<?php

namespace app\common\model;

use support\Model;

/**
 * 审计日志表
 * @property integer $id 主键
 * @property integer|null $actor_user_id 操作者用户id,系统操作为空
 * @property string $actor_type 操作者类型: platform/server/user/client
 * @property integer|null $server_id 相关空间id
 * @property string|null $client_id 相关应用client_id
 * @property string $action 动作名,如 user.login / binding.revoke
 * @property string $target_type 目标类型
 * @property string $target_id 目标id
 * @property array|null $detail 详情JSON
 * @property string $ip 来源IP
 * @property string|null $created_time 记录时间
 */
class AuditLogModel extends BaseModel
{
    protected $table = 'app_audit_logs';

    const CREATED_AT = "created_time";
    const UPDATED_AT = null;

    protected $casts = [
        'detail' => 'array',
    ];
}
