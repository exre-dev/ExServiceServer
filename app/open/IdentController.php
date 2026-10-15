<?php

namespace app\open;

use app\common\middleware\BindCodeAuth;
use support\Redis;
use support\Request;
use support\Response;

/**
 * 开放 API-游戏绑定 ticket 生成（玩家 /exbind 流程的插件侧入口）
 *
 * POST /api/open/ident/bind-ticket {ident, ident_type?}
 * 生成 6 位一次性 ticket, 5 分钟内由空间管理员在管理端录入消费。
 */
class IdentController
{
  protected $noNeedLogin = '*';

  /** ticket 键前缀（与空间管理端 IdentController::bind 的消费端约定一致） */
  private const TICKET_PREFIX = 'bind:tkt:';

  public function ticket(Request $request): Response
  {
    BindCodeAuth::assertActive($request);

    $ident = trim((string) $request->post('ident', ''));
    $identType = (string) ($request->post('ident_type', 'fivem_license') ?? 'fivem_license');
    if ($ident === '' || !in_array($identType, ['fivem_license', 'steam', 'discord', 'qq'], true)) {
      return fail("ident 必填, ident_type 为 fivem_license/steam/discord/qq", 400);
    }

    // 6 位数字 ticket, 允许前导零, 防碰撞循环
    do {
      $ticket = random_code(6, true);
    } while (Redis::exists(self::TICKET_PREFIX . $ticket));

    Redis::setex(self::TICKET_PREFIX . $ticket, 300, json_encode([
      'server_id' => (int) $request->open_server_id,
      'ident' => $ident,
      'ident_type' => $identType,
    ]));

    return success([
      'ticket' => $ticket,
      'expires_in' => 300,
    ]);
  }
}
