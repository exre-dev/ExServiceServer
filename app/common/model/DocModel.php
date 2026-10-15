<?php

namespace app\common\model;

/**
 * 文档表
 * @property integer $id 主键
 * @property string $owner_type 归属: platform/server
 * @property integer|null $server_id owner_type=server时的空间id
 * @property integer|null $category_id 分类id
 * @property string $title 标题
 * @property string $slug 全局唯一URL标识
 * @property string $content_md 正文Markdown
 * @property string $visibility 可见性: public/login/members/roles/servers/tags
 * @property array|null $visible_roles visibility=roles时的角色数组
 * @property array|null $visible_servers visibility=servers时可见的空间id数组
 * @property array|null $visible_tags visibility=tags时可见的用户标签id数组
 * @property string $status 状态: draft/published
 * @property integer|null $author_user_id 作者用户id
 * @property integer $version 版本号
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class DocModel extends BaseModel
{
    protected $table = 'app_docs';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    protected $casts = [
        'visible_roles' => 'array',
        'visible_servers' => 'array',
        'visible_tags' => 'array',
    ];
}
