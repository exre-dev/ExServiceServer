<?php

namespace app\system;

use OpenApi\Attributes as OA;

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

  #[OA\Get(
    path: '/api/health',
    operationId: 'systemHealth',
    summary: '数据库与 Redis 健康检查',
    description: '免登录，仍受首次安装保护。执行一次用户表查询和 Redis ping；任一连接异常由 ApiHandler 返回 HTTP 500。',
    tags: ['系统'],
    security: [],
    responses: [
      new OA\Response(
        response: 403,
        description: 'IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/SystemHealthData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
      new OA\Response(
        response: 500,
        description: '数据库或 Redis 检查异常；现有异常处理器返回 JSON 编码的 {message} 文本，响应沿用 text/html 类型',
        content: new OA\MediaType(
          mediaType: 'text/html',
          schema: new OA\Schema(
            type: 'string',
            description: 'JSON 文本，解码后包含字符串 message 字段',
            example: '{"message":"数据库检查异常"}',
          ),
        ),
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function index(Request $request): Response
  {
    \app\common\model\UserModel::query()->limit(1)->count(); // DB 连通性
    Redis::ping(); // Redis 连通性
    return success(['status' => 'ok']);
  }
}
