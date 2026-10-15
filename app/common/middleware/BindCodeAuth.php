<?php

namespace app\common\middleware;

use app\common\model\PluginBindingModel;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 开放 API 绑定码鉴权（/api/open/*, 机器对机器）
 *
 * 绑定码来源: 请求头 X-Bind-Code 优先, 其次 body.bind_code（validate 用）。
 * 中间件只负责"定位绑定记录"挂到请求上; 授权状态判断由各控制器决定
 * （validate 需要返回语义化 status, 其余接口用 assertActive）。
 */
class BindCodeAuth implements MiddlewareInterface
{
  public function process(Request $request, callable $next): Response
  {
    $code = trim((string) $request->header('x-bind-code', ''));
    if ($code === '') {
      $body = $request->post();
      $code = trim((string) ($body['bind_code'] ?? ''));
    }
    if ($code === '') {
      return fail("缺少绑定码", 403);
    }

    $binding = PluginBindingModel::with('plugin:id,slug,name,status')
      ->where('code', $code)
      ->first();
    if (!$binding) {
      return fail("绑定码无效", 403);
    }

    $request->binding = $binding;
    $request->open_server_id = (int) $binding->server_id;
    $request->open_plugin_slug = (string) ($binding->plugin->slug ?? '');

    return $next($request);
  }

  /**
   * 断言绑定授权完全生效（除 validate 外的接口统一调用）
   */
  public static function assertActive(Request $request): void
  {
    $b = $request->binding;
    $server = \app\common\model\ServerAccountModel::find($b->server_id);
    $ok = $b
      && $b->status === 'active'
      && (!$b->expires_time || strtotime((string) $b->expires_time) >= time())
      && $b->plugin && $b->plugin->status === 'online'
      && $server && $server->status === 'active';

    if (!$ok) {
      throw new \app\common\exception\ApiException("绑定码未生效或已失效");
    }
  }
}
