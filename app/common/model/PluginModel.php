<?php

namespace app\common\model;

use support\Model;

/**
 * 插件目录表
 * @property integer $id 主键
 * @property string $slug 插件唯一标识,如 ex-tuner
 * @property string $name 插件名称
 * @property string $summary 一句话简介
 * @property string $description_md 详情(Markdown)
 * @property string $status 状态: draft/online/offline
 * @property string $latest_version 最新版本号
 * @property integer|null $doc_category_id 平台文档默认分类id,预留
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class PluginModel extends BaseModel
{
    protected $table = 'app_plugins';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    /** 功能点字典 */
    public function features()
    {
        return $this->hasMany(PluginFeatureModel::class, 'plugin_id', 'id');
    }

    /** 空间绑定码 */
    public function bindings()
    {
        return $this->hasMany(PluginBindingModel::class, 'plugin_id', 'id');
    }
}
