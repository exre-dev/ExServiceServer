<?php

namespace app\open;

use OpenApi\Attributes as OA;

use app\common\middleware\BindCodeAuth;
use support\Request;
use support\Response;

/**
 * 开放 API-插件遥测上报（可选, v1 仅落日志文件）
 *
 * POST /api/open/telemetry {event, message?, data?}
 */
class TelemetryController
{
  protected $noNeedLogin = '*';

  #[OA\Post(
    path: '/api/open/telemetry',
    operationId: 'submitPluginTelemetry',
    summary: '提交插件遥测事件',
    description: '绑定码通过 X-Bind-Code 请求头传入；请求头为空时回退读取请求 body.bind_code，非空请求头优先。缺失或未知绑定码返回 403。绑定码非 active、已过期、插件不在线或空间非 active 时返回 400。遥测写入本地日志；event、message 与 data 当前均可省略。成功 data 为 []。',
    tags: ['开放插件'],
    security: [['bindCodeAuth' => []]],
    requestBody: new OA\RequestBody(
      required: false,
      content: [
        new OA\MediaType(
          mediaType: 'application/json',
          schema: new OA\Schema(
            ref: '#/components/schemas/PluginTelemetryRequest',
          ),
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/PluginTelemetryRequest',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/PluginTelemetryResponse',
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
  public function index(Request $request): Response
  {
    BindCodeAuth::assertActive($request);

    $line = json_encode(
      [
        'time' => date('Y-m-d H:i:s'),
        'server_id' => (int) $request->open_server_id,
        'plugin' => $request->open_plugin_slug,
        'ip' => $request->getRealIp() ?: '',
        'event' => (string) $request->post('event', ''),
        'message' => (string) ($request->post('message', '') ?? ''),
        'data' => $request->post('data'),
      ],
      JSON_UNESCAPED_UNICODE,
    );

    // 独立文件留存, 避免混入业务日志
    file_put_contents(
      runtime_path('logs/open-telemetry.log'),
      $line . PHP_EOL,
      FILE_APPEND | LOCK_EX,
    );

    return success();
  }
}
