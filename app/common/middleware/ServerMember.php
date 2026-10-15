<?php

namespace app\common\middleware;

use app\common\exception\ApiException;
use app\common\model\ServerMemberModel;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 空间成员校验（/server/{sid}/* 路由组用）
 *
 * 必须排在 CheckLogin 之后。解析路由参数 sid, 校验当前用户是
 * 该空间的 active 成员, 并挂载空间上下文供控制器使用。
 */
class ServerMember implements MiddlewareInterface
{
  public function process(Request $request, callable $next): Response
  {
    $uid = $request->uid ?? null;
    if ($uid === null) {
      throw new ApiException("请先登录");
    }

    $sid = (int) ($request->route ? $request->route->param('sid', 0) : 0);
    if ($sid <= 0) {
      throw new ApiException("缺少空间标识");
    }

    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->where('status', 'active')
      ->first();

    if (!$member) {
      throw new ApiException("未加入该空间或已被移出");
    }

    // 空间上下文: 控制器读 server_role 判断 owner/admin/member
    $request->server_id = $sid;
    $request->server_role = (string) $member->role_key;
    $request->server_member = $member;

    return $next($request);
  }

  /**
   * 断言当前用户是空间管理员（owner/admin）——空间管理端接口统一调用
   */
  public static function assertManager(Request $request): void
  {
    if (!in_array((string) ($request->server_role ?? ''), ['owner', 'admin'], true)) {
      throw new ApiException("需要空间管理员权限");
    }
  }

  /**
   * 断言当前用户是空间 Owner
   */
  public static function assertOwner(Request $request): void
  {
    if (($request->server_role ?? '') !== 'owner') {
      throw new ApiException("仅空间所有者可操作");
    }
  }
}
