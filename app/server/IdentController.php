<?php

namespace app\server;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\ServerMemberModel;
use app\common\model\UserIdentModel;
use app\common\model\UserModel;
use app\common\services\AuditService;

use support\Redis;
use support\Request;
use support\Response;

/**
 * 空间管理端-游戏绑定（玩家游戏内 /exbind 生成 6 位 ticket, 管理员录入完成绑定）
 */
class IdentController
{
  /** Redis ticket 键前缀（与蓝图 §6 约定一致, /open 生成端在 P3 实现） */
  private const TICKET_PREFIX = 'bind:tkt:';

  /**
   * 本空间绑定记录列表
   */
  public function index(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $list = UserIdentModel::where('bound_server_id', $sid)
      ->with('user:id,email,username,avatar')
      ->orderByDesc('id')
      ->get();

    return success(['list' => $list]);
  }

  /**
   * 录入绑定码: 校验 ticket → 绑定游戏标识到指定用户 → 自动入空间为 member
   * ticket 一次性（Redis get + del）, 300 秒有效, 与空间强校验
   */
  public function bind(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $ticket = trim((string) $request->post('ticket', ''));
    $uid = (int) $request->post('user_id', 0);
    if ($ticket === '' || $uid <= 0) {
      throw new ApiException('ticket 与 user_id 必填');
    }

    // 一次性取出
    $key = self::TICKET_PREFIX . $ticket;
    $raw = Redis::get($key);
    if (!$raw) {
      throw new ApiException('绑定码不存在或已过期, 请玩家在游戏内重新生成');
    }
    Redis::del($key);

    $tkt = json_decode($raw, true);
    if (!is_array($tkt) || (int) ($tkt['server_id'] ?? 0) !== $sid) {
      throw new ApiException('绑定码不属于当前空间');
    }
    $ident = (string) ($tkt['ident'] ?? '');
    $identType = (string) ($tkt['ident_type'] ?? 'fivem_license');
    if ($ident === '') {
      throw new ApiException('绑定码数据异常');
    }

    // 目标用户必须是空间成员（或自动加入）
    $user = UserModel::find($uid);
    if (!$user) {
      throw new ApiException('用户不存在');
    }

    // 游戏标识全局唯一
    $exists = UserIdentModel::where('ident_type', $identType)
      ->where('ident_value', $ident)
      ->first();
    if ($exists) {
      if ((int) $exists->user_id === $uid) {
        return success([], '该标识已绑定此用户, 无需重复绑定');
      }
      throw new ApiException('该游戏标识已绑定其他账号');
    }

    // 成员关系: 无则自动加入 member, banned 拒绝
    $member = ServerMemberModel::where('server_id', $sid)
      ->where('user_id', $uid)
      ->first();
    if ($member && $member->status === 'banned') {
      throw new ApiException('该用户已被空间禁用, 无法绑定');
    }
    if (!$member) {
      $member = new ServerMemberModel();
      $member->server_id = $sid;
      $member->user_id = $uid;
      $member->role_key = 'member';
      $member->source = 'bind';
      $member->joined_time = date('Y-m-d H:i:s');
    }
    $member->status = 'active';
    $member->save();

    $row = new UserIdentModel();
    $row->user_id = $uid;
    $row->ident_type = $identType;
    $row->ident_value = $ident;
    $row->bound_server_id = $sid;
    $row->created_time = date('Y-m-d H:i:s');
    $row->save();

    AuditService::log((int) $request->uid, 'server', 'ident.bind', [
      'server_id' => $sid,
      'target_type' => 'ident',
      'target_id' => $row->id,
      'detail' => ['ident' => '...' . substr($ident, -8), 'user_id' => $uid],
    ]);

    // 只回显尾号, 不回传完整标识
    return success(
      [
        'ident_tail' => '...' . substr($ident, -8),
        'user' => ['id' => (int) $user->id, 'username' => $user->username],
      ],
      '绑定成功, 玩家已加入空间',
    );
  }

  /**
   * 解绑（仅限本空间产生的绑定记录）
   */
  public function unbind(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $row = UserIdentModel::where('id', $id)
      ->where('bound_server_id', $sid)
      ->first();
    if (!$row) {
      throw new ApiException('绑定记录不存在或不属于本空间');
    }
    $row->delete();

    AuditService::log((int) $request->uid, 'server', 'ident.unbind', [
      'server_id' => $sid,
      'target_type' => 'ident',
      'target_id' => $id,
      'detail' => ['user_id' => $row->user_id],
    ]);
    return success([], '已解绑');
  }
}
