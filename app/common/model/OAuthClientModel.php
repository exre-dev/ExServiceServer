<?php

namespace app\common\model;

use support\Model;

/**
 * OAuth应用表(仅超管管理)
 * @property integer $id 主键
 * @property string $client_id 公开client_id
 * @property string $name 应用名称
 * @property string $type 类型: third_party/plugin
 * @property integer|null $server_id plugin类应用归属空间id,空=平台级
 * @property string $client_secret_hash client_secret的SHA-256哈希
 * @property array $redirect_uris 回调地址白名单
 * @property array $scopes 允许申请的scope列表
 * @property string $status 状态: active/suspended
 * @property string $note 对接备注,仅超管可见
 * @property integer|null $created_by 创建者用户id
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class OAuthClientModel extends BaseModel
{
    protected $table = 'app_oauth_clients';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    protected $casts = [
        'redirect_uris' => 'array',
        'scopes' => 'array',
    ];

    /** 序列化时隐藏的敏感字段 */
    protected $hidden = ['client_secret_hash'];

    /** 归属空间（plugin 类应用） */
    public function server()
    {
        return $this->belongsTo(ServerAccountModel::class, 'server_id', 'id');
    }
}
