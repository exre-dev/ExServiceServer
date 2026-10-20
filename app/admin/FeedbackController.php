<?php

namespace app\admin;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\model\FeedbackAttachmentModel;
use app\common\model\FeedbackModel;
use app\common\model\UserModel;
use app\common\services\AuditService;
use support\Request;
use support\Response;

/**
 * 反馈工单管理（平台管理端, /api/admin/feedback）
 */
class FeedbackController
{
  /**
   * 工单列表（分页 + 状态/类型/关键词筛选）
   */
  #[OA\Get(
    path: '/api/admin/feedback',
    operationId: 'adminFeedbackIndex',
    summary: '反馈工单列表',
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
        description: '标题不区分大小写模糊匹配，或精确匹配 UUID/恢复键',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'admin_status',
        in: 'query',
        required: false,
        description: '非空时筛选管理状态',
        schema: new OA\Schema(
          type: 'string',
          enum: ['pending', 'processing', 'resolved'],
        ),
      ),
      new OA\Parameter(
        name: 'kind',
        in: 'query',
        required: false,
        description: '非空时筛选反馈类型',
        schema: new OA\Schema(
          type: 'string',
          enum: [
            'bug',
            'feature',
            'asset',
            'request',
            'content',
            'campaign',
            'suggestion',
            'complaint',
          ],
        ),
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
                    ref: '#/components/schemas/AdminFeedbackListItem',
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
    $adminStatus = (string) $request->get('admin_status', '');
    $kind = (string) $request->get('kind', '');

    $q = FeedbackModel::query();
    if ($keyword !== '') {
      $q->where(function ($sub) use ($keyword) {
        $sub
          ->whereRaw("form->>'title' ILIKE ?", ["%{$keyword}%"])
          ->orWhere('uuid', $keyword)
          ->orWhere('submission_key', $keyword);
      });
    }
    if ($adminStatus !== '') {
      $q->where('admin_status', $adminStatus);
    }
    if ($kind !== '') {
      $q->where('kind', $kind);
    }

    $total = (clone $q)->count();
    $rows = (clone $q)
      ->with(['user:id,email,username'])
      ->orderByDesc('id')
      ->offset(($page - 1) * $pageSize)
      ->limit($pageSize)
      ->get();

    $list = array_map(function ($r) {
      return [
        'id' => (int) $r->id,
        'uuid' => $r->uuid,
        'submission_key' => $r->submission_key,
        'area' => $r->area,
        'kind' => $r->kind,
        'title' => (string) ($r->form['title'] ?? ''),
        'user' => $r->user
          ? [
            'id' => (int) $r->user->id,
            'email' => $r->user->email,
            'username' => $r->user->username,
          ]
          : null,
        'status' => $r->status,
        'admin_status' => $r->admin_status,
        'attachment_count' => FeedbackAttachmentModel::where(
          'submission_id',
          $r->id,
        )->count(),
        'created_time' => $r->created_time,
        'updated_time' => $r->updated_time,
      ];
    }, $rows->all());

    return success(['list' => array_values($list), 'total' => $total]);
  }

  /**
   * 工单详情（表单快照 + 附件）
   */
  #[OA\Get(
    path: '/api/admin/feedback/{id}',
    operationId: 'adminFeedbackDetail',
    summary: '反馈工单详情',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '记录 ID',
        schema: new OA\Schema(type: 'integer'),
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
              ref: '#/components/schemas/AdminFeedbackDetail',
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
        response: 404,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function detail(Request $request, int $id): Response
  {
    $row = FeedbackModel::with(['user:id,email,username'])->find($id);
    if (!$row) {
      throw new ApiException('工单不存在', 404);
    }
    $atts = FeedbackAttachmentModel::where('submission_id', $row->id)
      ->orderBy('ordinal')
      ->get();

    return success([
      'id' => (int) $row->id,
      'uuid' => $row->uuid,
      'submission_key' => $row->submission_key,
      'area' => $row->area,
      'kind' => $row->kind,
      'form' => $row->form,
      'user' => $row->user
        ? [
          'id' => (int) $row->user->id,
          'email' => $row->user->email,
          'username' => $row->user->username,
        ]
        : null,
      'status' => $row->status,
      'admin_status' => $row->admin_status,
      'admin_note' => $row->admin_note,
      'attachments' => array_map(
        fn($a) => [
          'id' => (int) $a->id,
          'uuid' => $a->uuid,
          'ordinal' => (int) $a->ordinal,
          'original_name' => $a->original_name,
          'content_type' => $a->content_type,
          'byte_length' => (int) $a->byte_length,
          'sha256' => $a->sha256,
          'status' => $a->status,
          'created_time' => $a->created_time,
        ],
        $atts->all(),
      ),
      'created_time' => $row->created_time,
      'updated_time' => $row->updated_time,
    ]);
  }

  /**
   * 处理状态推进（pending → processing → resolved, 可回退）
   */
  #[OA\Post(
    path: '/api/admin/feedback/{id}/status',
    operationId: 'adminFeedbackStatus',
    summary: '处理反馈工单',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。管理状态允许前进和回退；可同时更新管理员备注。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    parameters: [
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '记录 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(
            property: 'admin_status',
            type: 'string',
            enum: ['pending', 'processing', 'resolved'],
          ),
          new OA\Property(property: 'admin_note', type: 'string'),
        ],
        required: ['admin_status'],
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
              type: 'array',
              items: new OA\Items(type: 'string'),
              maxItems: 0,
              description: '当前成功响应为空数组 []',
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
        response: 404,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function status(Request $request, int $id): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ['admin_status'])) {
      throw new ApiException('参数不匹配');
    }
    $status = (string) $post['admin_status'];
    if (!in_array($status, ['pending', 'processing', 'resolved'], true)) {
      throw new ApiException('admin_status 只支持 pending/processing/resolved');
    }
    $row = FeedbackModel::find($id);
    if (!$row) {
      throw new ApiException('工单不存在', 404);
    }
    $row->admin_status = $status;
    if (array_key_exists('admin_note', $post)) {
      $row->admin_note = (string) $post['admin_note'];
    }
    $row->save();

    AuditService::log(null, 'platform', 'feedback.status', [
      'target_type' => 'feedback',
      'target_id' => $row->uuid,
      'detail' => ['admin_status' => $status],
    ]);
    return success([], '状态已更新');
  }
}
