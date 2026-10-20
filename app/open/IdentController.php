<?php

namespace app\open;

use OpenApi\Attributes as OA;

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

  #[OA\Post(
    path: '/api/open/ident/bind-ticket',
    operationId: 'createPluginBindTicket',
    summary: '生成游戏标识绑定票据',
    description: '绑定码通过 X-Bind-Code 请求头传入；请求头为空时回退读取请求 body.bind_code，非空请求头优先。缺失或未知绑定码返回 403。绑定码非 active、已过期、插件不在线或空间非 active 时返回 400。一次性六位数字票据有效 300 秒，由空间管理员录入消费。',
    tags: ['开放插件'],
    security: [['bindCodeAuth' => []]],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(
            ref: '#/components/schemas/PluginBindTicketRequest',
          ),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/PluginBindTicketRequest',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/PluginBindTicketResponse',
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        description: '身份被禁用、绑定码缺失或无效，或请求频率受限。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'public'],
  )]
  public function ticket(Request $request): Response
  {
    BindCodeAuth::assertActive($request);

    $ident = trim((string) $request->post('ident', ''));
    $identType =
      (string) ($request->post('ident_type', 'fivem_license') ??
        'fivem_license');
    if (
      $ident === '' ||
      !in_array($identType, ['fivem_license', 'steam', 'discord', 'qq'], true)
    ) {
      return fail(
        'ident 必填, ident_type 为 fivem_license/steam/discord/qq',
        400,
      );
    }

    // 6 位数字 ticket, 允许前导零, 防碰撞循环
    do {
      $ticket = random_code(6, true);
    } while (Redis::exists(self::TICKET_PREFIX . $ticket));

    Redis::setex(
      self::TICKET_PREFIX . $ticket,
      300,
      json_encode([
        'server_id' => (int) $request->open_server_id,
        'ident' => $ident,
        'ident_type' => $identType,
      ]),
    );

    return success([
      'ticket' => $ticket,
      'expires_in' => 300,
    ]);
  }
}
