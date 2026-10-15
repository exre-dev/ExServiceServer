<?php

namespace app\common\model;

use support\Model;

/**
 * 文档分类表
 * @property integer $id 主键
 * @property string $owner_type 归属: platform/server
 * @property integer|null $server_id owner_type=server时的空间id
 * @property string $name 分类名
 * @property integer $sort 排序,小的在前
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class DocCategoryModel extends BaseModel
{
    protected $table = 'app_doc_categories';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";
}
