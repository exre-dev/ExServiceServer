<?php

namespace app\open;

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
