<?php

namespace app\common\model;

use support\Model;

/**
 * 用户账号表
 * @property integer $id 用户唯一标识
 * @property string $email 登录邮箱,全局唯一
 * @property string $username 显示昵称
 * @property string $password_hash 密码哈希(bcrypt)
 * @property string $avatar 头像地址
 * @property string|null $qq 联系QQ号,可空,唯一
 * @property integer $level 用户等级,预留
 * @property string $platform_role 平台角色: ''/ops/superadmin
 * @property integer $ban 封禁标记: 0正常 1封禁
 * @property string|null $last_login_time 最近登录时间
 * @property string|null $created_time 创建时间
 * @property string|null $updated_time 更新时间
 */
class UserModel extends BaseModel
{
    protected $table = 'app_users';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    /** 序列化时隐藏的敏感字段 */
    protected $hidden = ['password_hash'];

    /** 空间成员关系 */
    public function members()
    {
        return $this->hasMany(ServerMemberModel::class, 'user_id', 'id');
    }

    /** 游戏标识列表 */
    public function idents()
    {
        return $this->hasMany(UserIdentModel::class, 'user_id', 'id');
    }

    /**
     * 自营服务器遗留接口: 判断 license 标识是否已绑定账号
     * 返回 [是否已绑定, 白名单待通过] (第二项待自营白名单迁移后实现, 见蓝图 §8)
     * @return array{0: bool, 1: bool}
     */
    public static function hasBindAndRegister($ident): array
    {
        $req = [false, false];
        $row = UserIdentModel::where('ident_type', 'fivem_license')
            ->where('ident_value', $ident)
            ->first();
        if ($row) {
            $req[0] = true;
        }
        return $req;
    }
}
