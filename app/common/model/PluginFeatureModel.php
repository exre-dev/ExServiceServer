<?php

namespace app\common\model;

use support\Model;

/**
 * 插件功能点字典
 * @property integer $id 主键
 * @property integer $plugin_id 所属插件id
 * @property string $feature_key 功能键,如 nitro
 * @property string $name 功能中文名
 * @property boolean $default_enabled 空间未显式配置时是否默认开启
 */
class PluginFeatureModel extends BaseModel
{
    protected $table = 'app_plugin_features';

    const CREATED_AT = null;
    const UPDATED_AT = null;
}
