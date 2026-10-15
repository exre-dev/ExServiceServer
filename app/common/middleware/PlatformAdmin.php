<?php

namespace app\common\middleware;

use app\common\exception\ApiException;
use app\common\model\UserModel;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 平台管理端角色校验（/admin/* 路由组用）
 *
 * 必须排在 CheckLogin 之后（依赖 $request->uid）。
 * 默认允许 superadmin + ops；仅超管可用的接口用 new PlatformAdmin(['superadmin'])。
 */
class PlatformAdmin implements MiddlewareInterface
{
  /** 允许通过的平台角色 */
  protected array $roles;

  public function __construct(array $roles = ['superadmin', 'ops'])
  {
    $this->roles = $roles;
  }

  public function process(Request $request, callable $next): Response
  {
    $uid = $request->uid ?? null;
    if ($uid === null) {
      throw new ApiException("请先登录");
    }

    $user = UserModel::find($uid);
    if (!$user || !in_array((string) $user->platform_role, $this->roles, true)) {
      throw new ApiException("无权限访问平台管理端");
    }

    // 平台角色挂到请求上, 控制器可直接区分超管/运营
    $request->platform_role = (string) $user->platform_role;

    return $next($request);
  }
}
