<?php

namespace app\common\model;

use support\Model;

/**
 * 插件绑定码表(服务开通凭证)
 * @property integer $id 主键
 * @property integer $server_id 空间id
 * @property integer $plugin_id 插件id
 * @property string $code 绑定码 EX-XXXX-XXXX-XXXX-XXXX-XXXX
 * @property string $status 状态: active/revoked/disabled
 * @property string $server_key_hash 首次校验锁定的sv_licenseKey SHA-256
 * @property array $features 该空间开通的功能面 {feature_key:bool}
 * @property string|null $expires_time 过期时间,空=长期
 * @property string|null $last_heartbeat 最近心跳时间
 * @property string $last_heartbeat_ip 最近心跳来源IP
 * @property integer|null $created_by 生成者用户id
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class PluginBindingModel extends BaseModel
{
    protected $table = 'app_plugin_bindings';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    /** features JSONB 自动编解码 */
    protected $casts = [
        'features' => 'array',
    ];

    /** 所属空间 */
    public function server()
    {
        return $this->belongsTo(ServerAccountModel::class, 'server_id', 'id');
    }

    /** 所属插件 */
    public function plugin()
    {
        return $this->belongsTo(PluginModel::class, 'plugin_id', 'id');
    }
}
