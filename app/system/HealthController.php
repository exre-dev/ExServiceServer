<?php

namespace app\system;

use support\Redis;
use support\Request;
use support\Response;

/**
 * 健康检查（/api/health, 契约 system 标签）
 *
 * DB/Redis 任一异常直接 500（由兜底 ApiHandler 返回）。
 */
class HealthController
{
  /**
   * 不需要登录验证的方法
   * @var string[]
   */
  protected $noNeedLogin = ['index'];

  public function index(Request $request): Response
  {
    \app\common\model\UserModel::query()->limit(1)->count(); // DB 连通性
    Redis::ping();                                             // Redis 连通性
    return success(['status' => 'ok']);
  }
}
