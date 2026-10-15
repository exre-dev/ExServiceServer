<?php

namespace app\admin;

use app\common\exception\ApiException;
use app\common\model\AuditLogModel;
use app\common\model\AuthSessionModel;
use app\common\model\ServerMemberModel;
use app\common\model\UserModel;
use app\common\services\AuditService;
use app\common\services\SessionService;

use support\Request;
use support\Response;

/**
 * 平台管理端-用户管理（C 端账号）
 */
class UserController
{
  /**
   * 用户列表（搜索/筛选/分页）
   */
  public function index(Request $request): Response
  {
    $page = max(1, (int) $request->get('page', 1));
    $pageSize = min(50, max(1, (int) $request->get('page_size', 10)));
    $keyword = trim((string) $request->get('keyword', ''));
    $ban = $request->get('ban', '');

    $q = UserModel::query();
    if ($keyword !== '') {
      $q->where(function ($sq) use ($keyword) {
        $sq->where('email', 'like', "%{$keyword}%")
          ->orWhere('username', 'like', "%{$keyword}%")
          ->orWhere('qq', 'like', "%{$keyword}%");
      });
    }
    if ($ban !== '' && $ban !== null) {
      $q->where('ban', (int) $ban);
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    return success([
      'list' => $pager->items(),
      'total' => $pager->total(),
    ]);
  }

  /**
   * 用户详情（信息 + 会话设备 + 加入的空间）
   */
  public function detail(Request $request, int $id): Response
  {
    $user = UserModel::find($id);
    if (!$user) {
      throw new ApiException("用户不存在");
    }

    $sessions = AuthSessionModel::where('user_id', $id)
      ->orderByDesc('id')
      ->limit(50)
      ->get();

    $servers = ServerMemberModel::where('user_id', $id)
      ->with('server:id,name,slug,status')
      ->get();

    $logs = AuditLogModel::where('actor_user_id', $id)
      ->orderByDesc('id')
      ->limit(20)
      ->get();

    $tags = \app\common\model\UserTagModel::where('user_id', $id)
      ->with('tag:id,name')
      ->get()
      ->map(fn ($r) => ['id' => (int) $r->tag_id, 'name' => $r->tag?->name])
      ->values();

    return success([
      'user' => $user,
      'sessions' => $sessions,
      'servers' => $servers,
      'tags' => $tags,
      'recent_logs' => $logs,
    ]);
  }

  /**
   * 封禁/解封（封禁即吊销全部会话）
   */
  public function ban(Request $request, int $id): Response
  {
    $user = UserModel::find($id);
    if (!$user) {
      throw new ApiException("用户不存在");
    }
    if ($id === (int) $request->uid) {
      throw new ApiException("不能封禁自己");
    }
    // 超管账号只有超管能动
    if ((string) $user->platform_role === 'superadmin' && $request->platform_role !== 'superadmin') {
      throw new ApiException("仅超级管理员可操作超管账号");
    }

    $ban = (int) $request->post('ban', 1) === 1 ? 1 : 0;
    $user->ban = $ban;
    $user->save();

    if ($ban === 1) {
      SessionService::revokeAllForUser($id);
    }

    AuditService::log((int) $request->uid, 'platform', 'user.ban', [
      'target_type' => 'user',
      'target_id' => $id,
      'detail' => ['ban' => $ban],
    ]);
    return success([], $ban ? "已封禁并下线全部会话" : "已解封");
  }

  /**
   * 设置平台角色（''/ops/superadmin; superadmin 目标仅超管可操作）
   */
  public function platform_role(Request $request, int $id): Response
  {
    $user = UserModel::find($id);
    if (!$user) {
      throw new ApiException("用户不存在");
    }

    $role = (string) $request->post('role', '');
    if (!in_array($role, ['', 'ops', 'superadmin'], true)) {
      throw new ApiException("非法角色值");
    }
    if ($role === 'superadmin' && $request->platform_role !== 'superadmin') {
      throw new ApiException("仅超级管理员可授予超管角色");
    }

    $user->platform_role = $role;
    $user->save();

    AuditService::log((int) $request->uid, 'platform', 'user.platform_role', [
      'target_type' => 'user',
      'target_id' => $id,
      'detail' => ['role' => $role],
    ]);
    return success([], "平台角色已更新");
  }
}
