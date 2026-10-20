<?php

namespace app\open;

use OpenApi\Attributes as OA;

use app\common\middleware\BindCodeAuth;
use app\common\model\ServerMemberModel;
use app\common\model\UserIdentModel;
use app\common\services\PermService;
use support\Redis;
use support\Request;
use support\Response;

/**
 * 开放 API-成员权限批量下发（插件心跳顺带拉取, 进服零请求）
 *
 * GET /api/open/perm/batch （X-Bind-Code 鉴权, 插件由绑定码本身确定）
 * 返回该空间全部 active 成员的 fivem_license 标识及最终权限, 60s 缓存。
 */
class PermController
{
  protected $noNeedLogin = '*';

  /**
   * 权限版本号（EXCore ≥1.1 每 30s 轮询, 版本变化即拉全量——「下发权限」手动同步通道）
   */
  #[OA\Get(
    path: '/api/open/perm/rev',
    operationId: 'getPluginPermissionRevision',
    summary: '获取权限版本号',
    description: '绑定码通过 X-Bind-Code 请求头传入；请求头为空时回退读取请求 body.bind_code，非空请求头优先。缺失或未知绑定码返回 403。绑定码非 active、已过期、插件不在线或空间非 active 时返回 400。GET 推荐使用请求头鉴权；未初始化的权限版本为 0。',
    tags: ['开放插件'],
    security: [['bindCodeAuth' => []]],
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/PluginPermissionRevisionResponse',
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
  public function rev(Request $request): Response
  {
    BindCodeAuth::assertActive($request);

    $serverId = (int) $request->open_server_id;
    $slug = $request->open_plugin_slug;

    return success([
      'rev' => (int) (Redis::get("perm:rev:{$serverId}:{$slug}") ?: 0),
    ]);
  }

  #[OA\Get(
    path: '/api/open/perm/batch',
    operationId: 'getPluginPermissions',
    summary: '批量获取空间成员插件权限',
    description: '绑定码通过 X-Bind-Code 请求头传入；请求头为空时回退读取请求 body.bind_code，非空请求头优先。缺失或未知绑定码返回 403。绑定码非 active、已过期、插件不在线或空间非 active 时返回 400。GET 推荐使用请求头鉴权；返回全部 active 成员的 fivem_license 标识与最终权限，缓存 60 秒。',
    tags: ['开放插件'],
    security: [['bindCodeAuth' => []]],
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功。',
        content: new OA\JsonContent(
          ref: '#/components/schemas/PluginPermissionBatchResponse',
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
  public function batch(Request $request): Response
  {
    BindCodeAuth::assertActive($request);

    $binding = $request->binding;
    $serverId = (int) $request->open_server_id;
    $slug = $request->open_plugin_slug;

    $cacheKey = "perm:{$serverId}:{$slug}";
    $cached = Redis::get($cacheKey);
    if ($cached !== null) {
      return success(json_decode($cached, true) ?: []);
    }

    // 本空间 active 成员
    $memberIds = ServerMemberModel::where('server_id', $serverId)
      ->where('status', 'active')
      ->pluck('user_id')
      ->all();
    if (!$memberIds) {
      $payload = ['list' => []];
      Redis::setex($cacheKey, 60, json_encode($payload));
      return success($payload);
    }

    // 成员的 fivem_license 标识 → 统一权限判定
    $idents = UserIdentModel::where('ident_type', 'fivem_license')
      ->whereIn('user_id', $memberIds)
      ->get();

    $list = [];
    foreach ($idents as $row) {
      $perm = PermService::canUse((int) $row->user_id, $serverId, $slug);
      if ($perm === null) {
        continue;
      }
      $list[] = [
        'ident' => (string) $row->ident_value,
        'allowed' => (bool) $perm['allowed'],
        'features' => $perm['features'],
      ];
    }

    $payload = ['list' => $list];
    Redis::setex($cacheKey, 60, json_encode($payload));
    return success($payload);
  }
}
