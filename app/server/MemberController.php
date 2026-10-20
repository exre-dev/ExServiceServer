<?php

namespace app\server;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\ServerMemberModel;
use app\common\model\UserModel;
use app\common\services\AuditService;

use support\Request;
use support\Response;

/**
 * 空间管理端-成员管理（无邀请/申请模式, 管理员检索用户库直接添加）
 */
class MemberController
{
  /**
   * 成员列表
   */
  public function index(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $list = ServerMemberModel::where('server_id', $sid)
      ->with('user:id,email,username,avatar,qq')
      ->orderByDesc('id')
      ->get();

    return success([
      'list' => $list,
      'my_role' => (string) $request->server_role,
    ]);
  }

  /**
   * 添加成员（按邮箱或 QQ 精确匹配用户库; 已离开成员重新激活）
   */
  public function add(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $keyword = trim((string) $request->post('keyword', ''));
    $role = (string) $request->post('role', 'member');
    if ($keyword === '' || !in_array($role, ['admin', 'member'], true)) {
      throw new ApiException(
        'keyword 必填, role 为 admin/member（owner 转让暂未开放）',
      );
    }

    $user = UserModel::where('email', $keyword)
      ->orWhere('qq', $keyword)
      ->first();
    if (!$user) {
      throw new ApiException('未找到匹配的用户（邮箱/QQ）');
    }
    if ((int) $user->ban !== 0) {
      throw new ApiException('该用户已被平台封禁');
    }

    // admin 角色仅 owner 可授予
    if ($role === 'admin' && $request->server_role !== 'owner') {
      throw new ApiException('仅 Owner 可添加管理员');
    }

    $existing = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $user->id)
      ->first();
    if ($existing) {
      if ($existing->role_key === 'owner') {
        throw new ApiException('该用户是空间 Owner');
      }
      if ($existing->status === 'banned') {
        throw new ApiException('该成员已被禁用, 请先解除禁用');
      }
      // 重新加入/调整
      $existing->status = 'active';
      $existing->role_key = $role;
      $existing->joined_time = date('Y-m-d H:i:s');
      $existing->save();
    } else {
      $member = new ServerMemberModel();
      $member->server_id = $sid;
      $member->user_id = $user->id;
      $member->role_key = $role;
      $member->status = 'active';
      $member->source = 'manual';
      $member->joined_time = date('Y-m-d H:i:s');
      $member->save();
    }

    AuditService::log((int) $request->uid, 'server', 'member.add', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $user->id,
      'detail' => ['role' => $role, 'keyword' => $keyword],
    ]);
    return success([], '成员已添加');
  }

  /**
   * 调整成员角色（仅 Owner）
   */
  public function role(Request $request, int $sid, int $uid): Response
  {
    ServerMember::assertOwner($request);

    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在');
    }
    if ($member->role_key === 'owner') {
      throw new ApiException('不能调整 Owner 角色');
    }

    $role = (string) $request->post('role', '');
    if (!in_array($role, ['admin', 'member'], true)) {
      throw new ApiException('role 为 admin/member');
    }
    $member->role_key = $role;
    $member->save();

    AuditService::log((int) $request->uid, 'server', 'member.role', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
      'detail' => ['role' => $role],
    ]);
    return success([], '角色已调整');
  }

  /**
   * 移除成员（软离开 left）
   */
  public function remove(Request $request, int $sid, int $uid): Response
  {
    ServerMember::assertManager($request);

    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在');
    }
    $this->assertCanActOn($request, $member, $uid);
    $member->status = 'left';
    $member->save();

    AuditService::log((int) $request->uid, 'server', 'member.remove', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
    ]);
    return success([], '成员已移除');
  }

  /**
   * 禁用成员（banned, 游戏权限判定按非成员处理）
   */
  public function ban(Request $request, int $sid, int $uid): Response
  {
    ServerMember::assertManager($request);

    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在');
    }
    $this->assertCanActOn($request, $member, $uid);
    $member->status = 'banned';
    $member->save();

    AuditService::log((int) $request->uid, 'server', 'member.ban', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
    ]);
    return success([], '成员已禁用');
  }

  /**
   * 解除禁用
   */
  public function unban(Request $request, int $sid, int $uid): Response
  {
    ServerMember::assertManager($request);

    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if (!$member) {
      throw new ApiException('成员不存在');
    }
    if ($member->role_key === 'owner') {
      throw new ApiException('不能对 Owner 执行该操作');
    }
    if ($member->status !== 'banned') {
      throw new ApiException('该成员未被禁用');
    }
    $member->status = 'active';
    $member->save();

    AuditService::log((int) $request->uid, 'server', 'member.unban', [
      'server_id' => $sid,
      'target_type' => 'user',
      'target_id' => $uid,
    ]);
    return success([], '已解除禁用');
  }

  /**
   * 操作保护: 不能动自己 / admin 只能动 member, owner/admin 中的 admin 只能由 owner 动
   */
  private function assertCanActOn(Request $request, $member, int $uid): void
  {
    if ($uid === (int) $request->uid) {
      throw new ApiException('不能对自己执行该操作');
    }
    if ($member->role_key === 'owner') {
      throw new ApiException('不能对 Owner 执行该操作');
    }
    if ($member->role_key === 'admin' && $request->server_role !== 'owner') {
      throw new ApiException('仅 Owner 可操作管理员');
    }
  }
}
