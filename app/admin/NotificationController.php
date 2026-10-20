<?php

namespace app\admin;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\model\NotificationModel;
use app\common\model\UserModel;
use app\common\services\AuditService;
use app\common\services\NotificationService;
use support\Request;
use support\Response;

/**
 * 站内通知管理（平台管理端, /api/admin/notifications）
 *
 * 发送按批（batch_uuid）：community 广播给全体用户各插一条回执, personal 发给指定邮箱用户；
 * 撤回（置过期）与删除均按批操作。列表按批聚合展示。
 */
class NotificationController
{
  private const CATEGORIES = [
    'announcement',
    'personal_update',
    'service_notice',
  ];

  /**
   * 批次列表（聚合, 分页 + 标题搜索）
   */
  #[OA\Get(
    path: '/api/admin/notifications',
    operationId: 'adminNotificationIndex',
    summary: '通知批次列表',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'page',
        in: 'query',
        required: false,
        description: '页码，小于1按1处理',
        schema: new OA\Schema(type: 'integer', minimum: 1, default: 1),
      ),
      new OA\Parameter(
        name: 'page_size',
        in: 'query',
        required: false,
        description: '每页数量，服务端裁剪到允许范围',
        schema: new OA\Schema(
          type: 'integer',
          minimum: 1,
          maximum: 50,
          default: 10,
        ),
      ),
      new OA\Parameter(
        name: 'keyword',
        in: 'query',
        required: false,
        description: '关键词，去除首尾空白后模糊搜索',
        schema: new OA\Schema(type: 'string'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功',
        content: new OA\JsonContent(
          type: 'object',
          properties: [
            new OA\Property(
              property: 'msg',
              type: 'string',
              description: '成功消息',
            ),
            new OA\Property(
              property: 'data',
              type: 'object',
              properties: [
                new OA\Property(
                  property: 'list',
                  type: 'array',
                  items: new OA\Items(
                    ref: '#/components/schemas/AdminNotificationBatch',
                  ),
                ),
                new OA\Property(property: 'total', type: 'integer'),
              ],
            ),
          ],
          required: ['msg', 'data'],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function index(Request $request): Response
  {
    $page = max(1, (int) $request->get('page', 1));
    $pageSize = min(50, max(1, (int) $request->get('page_size', 10)));
    $keyword = trim((string) $request->get('keyword', ''));

    $base = NotificationModel::query();
    if ($keyword !== '') {
      $base->where('title', 'ilike', "%{$keyword}%");
    }

    $total = (clone $base)
      ->selectRaw('COUNT(DISTINCT batch_uuid)')
      ->value('count');
    $rows = (clone $base)
      ->selectRaw(
        'batch_uuid, MIN(title) AS title, MIN(category) AS category, MIN(scope) AS scope, MIN(necessity) AS necessity',
      )
      ->selectRaw(
        'MAX(id) AS latest_id, MIN(created_time) AS created_time, COUNT(*) AS recipients',
      )
      ->selectRaw('SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread')
      ->selectRaw('MAX(expires_time) AS expires_time')
      ->groupBy('batch_uuid')
      ->orderByDesc('latest_id')
      ->offset(($page - 1) * $pageSize)
      ->limit($pageSize)
      ->get();

    $now = date('Y-m-d H:i:s');
    $list = array_map(function ($r) use ($now) {
      $expired = $r->expires_time && $r->expires_time <= $now;
      return [
        'batch_uuid' => $r->batch_uuid,
        'title' => $r->title,
        'category' => $r->category,
        'scope' => $r->scope,
        'necessity' => $r->necessity,
        'recipients' => (int) $r->recipients,
        'unread' => (int) $r->unread,
        'expires_time' => $r->expires_time,
        'status' => $expired ? 'expired' : 'active',
        'created_time' => $r->created_time,
      ];
    }, $rows->all());

    return success(['list' => array_values($list), 'total' => (int) $total]);
  }

  /**
   * 发送通知（community=全体广播 / personal=指定邮箱用户）
   */
  #[OA\Post(
    path: '/api/admin/notifications',
    operationId: 'adminNotificationCreate',
    summary: '发送站内通知',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。community 广播给全部用户，personal 发给指定邮箱用户；为每位接收者保存独立回执。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(
            property: 'scope',
            type: 'string',
            enum: ['community', 'personal'],
          ),
          new OA\Property(
            property: 'category',
            type: 'string',
            enum: ['announcement', 'personal_update', 'service_notice'],
          ),
          new OA\Property(
            property: 'title',
            type: 'string',
            minLength: 1,
            maxLength: 240,
          ),
          new OA\Property(
            property: 'necessity',
            type: 'string',
            enum: ['optional', 'necessary'],
            default: 'optional',
          ),
          new OA\Property(
            property: 'summary_text',
            type: 'string',
            maxLength: 320,
          ),
          new OA\Property(
            property: 'body_html',
            type: 'string',
            description: '内容由管理端约束；body_text 缺省时从 HTML 派生纯文本',
          ),
          new OA\Property(property: 'body_text', type: 'string'),
          new OA\Property(
            property: 'expires_time',
            type: 'string',
            description: '空字符串为不过期；否则格式 YYYY-MM-DD HH:mm:ss',
            pattern: '^$|^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
          ),
          new OA\Property(
            property: 'target_email',
            type: 'string',
            description: 'scope=personal 时必填且对应用户必须存在',
          ),
          new OA\Property(
            property: 'actions',
            description: '动作数组或编码该数组的 JSON 字符串；控制器不逐字段校验，按原样存储。',
            oneOf: [
              new OA\Schema(
                type: 'array',
                items: new OA\Items(
                  type: 'object',
                  properties: [
                    new OA\Property(property: 'actionId', type: 'string'),
                    new OA\Property(property: 'kind', type: 'string'),
                    new OA\Property(property: 'label', type: 'string'),
                    new OA\Property(property: 'emphasis', type: 'string'),
                    new OA\Property(property: 'enabled', type: 'boolean'),
                    new OA\Property(
                      property: 'unavailableReason',
                      type: 'string',
                      nullable: true,
                    ),
                    new OA\Property(
                      property: 'navigationTarget',
                      type: 'object',
                      properties: [],
                      additionalProperties: true,
                    ),
                  ],
                  additionalProperties: true,
                ),
              ),
              new OA\Schema(type: 'string'),
            ],
          ),
        ],
        required: ['scope', 'category', 'title'],
      ),
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功',
        content: new OA\JsonContent(
          type: 'object',
          properties: [
            new OA\Property(
              property: 'msg',
              type: 'string',
              description: '成功消息',
            ),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/AdminNotificationCreated',
            ),
          ],
          required: ['msg', 'data'],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function create(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ['scope', 'category', 'title'])) {
      throw new ApiException('参数不匹配');
    }

    $scope = (string) $post['scope'];
    if (!in_array($scope, ['community', 'personal'], true)) {
      throw new ApiException('scope 只支持 community/personal');
    }
    $category = (string) $post['category'];
    if (!in_array($category, self::CATEGORIES, true)) {
      throw new ApiException(
        'category 只支持 ' . implode('/', self::CATEGORIES),
      );
    }
    $necessity = $post['necessity'] ?? 'optional';
    if (!in_array($necessity, ['optional', 'necessary'], true)) {
      throw new ApiException('necessity 只支持 optional/necessary');
    }

    $title = trim((string) $post['title']);
    if ($title === '' || mb_strlen($title) > 240) {
      throw new ApiException('标题需为 1-240 个字符');
    }
    $summary = trim((string) ($post['summary_text'] ?? ''));
    if (mb_strlen($summary) > 320) {
      throw new ApiException('摘要不能超过 320 个字符');
    }
    $bodyHtml = trim((string) ($post['body_html'] ?? ''));
    $bodyText = trim((string) ($post['body_text'] ?? ''));
    if ($bodyText === '' && $bodyHtml !== '') {
      // 纯文本回退缺省时从 HTML 派生（保证 contentFallbackText 非空）
      $bodyText = trim(
        strip_tags(
          str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $bodyHtml),
        ),
      );
    }
    $expires = trim((string) ($post['expires_time'] ?? ''));
    if (
      $expires !== '' &&
      !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires)
    ) {
      throw new ApiException('过期时间格式应为 YYYY-MM-DD HH:mm:ss');
    }

    // 动作数组（可选, 直接存 JSONB, 结构由管理端保证）
    $actions = $post['actions'] ?? null;
    if (is_string($actions) && $actions !== '') {
      $decoded = json_decode($actions, true);
      if (!is_array($decoded)) {
        throw new ApiException('actions 必须是合法 JSON 数组');
      }
      $actions = $decoded;
    }
    if ($actions === '' || $actions === null) {
      $actions = [];
    }

    // 目标用户集合
    if ($scope === 'personal') {
      $email = trim((string) ($post['target_email'] ?? ''));
      if ($email === '') {
        throw new ApiException('个人通知必须提供 target_email');
      }
      $targets = UserModel::where('email', $email)->get(['id']);
      if ($targets->isEmpty()) {
        throw new ApiException('目标用户不存在');
      }
    } else {
      $targets = UserModel::get(['id']);
    }

    $batch = NotificationService::uuid();
    $now = date('Y-m-d H:i:s');
    $rows = [];
    foreach ($targets as $u) {
      $rows[] = [
        'uuid' => NotificationService::uuid(),
        'batch_uuid' => $batch,
        'user_id' => (int) $u->id,
        'scope' => $scope,
        'category' => $category,
        'necessity' => $necessity,
        'title' => $title,
        'summary_text' => $summary,
        'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
        'body_text' => $bodyText !== '' ? $bodyText : null,
        'actions' => json_encode($actions),
        'expires_time' => $expires !== '' ? $expires : null,
        'created_time' => $now,
        'updated_time' => $now,
      ];
    }
    NotificationModel::query()->insert($rows);

    AuditService::log(null, 'platform', 'notification.send', [
      'target_type' => 'notification_batch',
      'target_id' => $batch,
      'detail' => [
        'scope' => $scope,
        'recipients' => count($rows),
        'title' => $title,
      ],
    ]);

    return success(
      ['batch_uuid' => $batch, 'recipients' => count($rows)],
      '通知已发送给 ' . count($rows) . ' 位用户',
    );
  }

  /**
   * 撤回（按批置过期, 用户侧立即从未读/列表消失）
   */
  #[OA\Post(
    path: '/api/admin/notifications/{batch}/expire',
    operationId: 'adminNotificationExpire',
    summary: '撤回通知批次',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。按批置过期；不存在或全部已过期返回400。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'batch',
        in: 'path',
        required: true,
        description: '通知批次 UUID',
        schema: new OA\Schema(type: 'string'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功',
        content: new OA\JsonContent(
          type: 'object',
          properties: [
            new OA\Property(
              property: 'msg',
              type: 'string',
              description: '成功消息',
            ),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/AdminAffected',
            ),
          ],
          required: ['msg', 'data'],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function expire(Request $request, string $batch): Response
  {
    $now = date('Y-m-d H:i:s');
    $affected = NotificationModel::where('batch_uuid', $batch)
      ->where(function ($q) use ($now) {
        $q->where('expires_time', null)->orWhere('expires_time', '>', $now);
      })
      ->update(['expires_time' => $now, 'updated_time' => $now]);
    if ($affected === 0) {
      throw new ApiException('批次不存在或已过期');
    }

    AuditService::log(null, 'platform', 'notification.expire', [
      'target_type' => 'notification_batch',
      'target_id' => $batch,
    ]);
    return success(['affected' => $affected], "已撤回 {$affected} 条通知");
  }

  /**
   * 删除（按批物理删除, 不可恢复）
   */
  #[OA\Delete(
    path: '/api/admin/notifications/{batch}',
    operationId: 'adminNotificationDelete',
    summary: '删除通知批次',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。物理删除整批通知；批次不存在返回400。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'batch',
        in: 'path',
        required: true,
        description: '通知批次 UUID',
        schema: new OA\Schema(type: 'string'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '操作成功',
        content: new OA\JsonContent(
          type: 'object',
          properties: [
            new OA\Property(
              property: 'msg',
              type: 'string',
              description: '成功消息',
            ),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/AdminAffected',
            ),
          ],
          required: ['msg', 'data'],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function delete(Request $request, string $batch): Response
  {
    $affected = NotificationModel::where('batch_uuid', $batch)->delete();
    if ($affected === 0) {
      throw new ApiException('批次不存在');
    }

    AuditService::log(null, 'platform', 'notification.delete', [
      'target_type' => 'notification_batch',
      'target_id' => $batch,
      'detail' => ['deleted' => $affected],
    ]);
    return success(['affected' => $affected], "已删除 {$affected} 条通知");
  }
}
