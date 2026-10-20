<?php

namespace app\notify;

use app\common\exception\ApiException;
use app\common\services\NotificationService;
use OpenApi\Attributes as OA;
use support\Request;
use support\Response;

/**
 * 站内通知（用户侧网站, /api/notifications* 与 /api/notification-preferences）
 *
 * 输出字段名遵循 ExWebsite Contract（camelCase），响应走全站 {msg,data} 方言。
 */
class NotificationController
{
  /**
   * 通知列表（keyset 分页, view=unread|history）
   */
  #[OA\Get(
    path: '/api/notifications',
    operationId: 'notificationList',
    summary: '通知列表与签名游标分页',
    tags: ['通知'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'view',
        in: 'query',
        required: true,
        schema: new OA\Schema(type: 'string', enum: ['unread', 'history']),
      ),
      new OA\Parameter(
        name: 'scope',
        in: 'query',
        required: false,
        schema: new OA\Schema(
          type: 'string',
          enum: ['all', 'community', 'personal'],
          default: 'all',
        ),
      ),
      new OA\Parameter(
        name: 'category',
        in: 'query',
        required: false,
        schema: new OA\Schema(
          type: 'string',
          enum: ['announcement', 'personal_update', 'service_notice'],
        ),
      ),
      new OA\Parameter(
        name: 'limit',
        in: 'query',
        required: false,
        schema: new OA\Schema(
          type: 'integer',
          default: 20,
          description: '取值 1 至 50；越界时回退到 20',
        ),
      ),
      new OA\Parameter(
        name: 'cursor',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'string'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          ref: '#/components/schemas/NotificationListResponse',
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function index(Request $request): Response
  {
    $view = (string) $request->get('view', '');
    if (!in_array($view, ['unread', 'history'], true)) {
      throw new ApiException('view 参数无效');
    }
    $scope = (string) $request->get('scope', 'all');
    if (!in_array($scope, ['all', 'community', 'personal'], true)) {
      throw new ApiException('scope 参数无效');
    }
    $category = (string) $request->get('category', '');
    if (
      $category !== '' &&
      !in_array(
        $category,
        ['announcement', 'personal_update', 'service_notice'],
        true,
      )
    ) {
      throw new ApiException('category 参数无效');
    }
    $limit = (int) $request->get('limit', 20);
    if ($limit < 1 || $limit > 50) {
      $limit = 20;
    }
    $cursor = $request->get('cursor');

    $data = NotificationService::list(
      (int) $request->uid,
      $view,
      $scope,
      $category,
      $cursor,
      $limit,
    );
    $data['readThroughToken'] = NotificationService::summary(
      (int) $request->uid,
    )['readThroughToken'];
    $data['serverTime'] = time() * 1000;
    return success($data);
  }

  /**
   * 未读摘要（铃铛轮询）
   */
  #[OA\Get(
    path: '/api/notifications/summary',
    operationId: 'notificationSummary',
    summary: '未读摘要',
    tags: ['通知'],
    security: [['sessionAuth' => []]],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          ref: '#/components/schemas/NotificationSummaryResponse',
        ),
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function summary(Request $request): Response
  {
    return success(NotificationService::summary((int) $request->uid));
  }

  /**
   * 通知详情（读详情不改变已读状态）
   */
  #[OA\Get(
    path: '/api/notifications/{notificationId}',
    operationId: 'notificationDetail',
    summary: '通知详情（不自动已读）',
    tags: ['通知'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'notificationId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          ref: '#/components/schemas/NotificationDetailResponse',
        ),
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
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
    x: ['audience' => 'internal'],
  )]
  public function detail(Request $request, string $notificationId): Response
  {
    $detail = NotificationService::detail((int) $request->uid, $notificationId);
    if ($detail === []) {
      throw new ApiException('通知不存在', 404);
    }
    return success($detail);
  }

  /**
   * 单条已读/未读（乐观锁 409）
   */
  #[OA\Patch(
    path: '/api/notifications/{notificationId}/state',
    operationId: 'notificationSetState',
    summary: '更新已读状态与修订号',
    tags: ['通知'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'notificationId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\JsonContent(
          ref: '#/components/schemas/NotificationStateRequest',
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          ref: '#/components/schemas/NotificationStateResponse',
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 404,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 409,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function state(Request $request, string $notificationId): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ['read', 'expectedRevision'])) {
      throw new ApiException('参数不匹配');
    }
    $result = NotificationService::setState(
      (int) $request->uid,
      $notificationId,
      (bool) $post['read'],
      (int) $post['expectedRevision'],
    );
    if (!empty($result['missing'])) {
      throw new ApiException('通知不存在', 404);
    }
    if (!empty($result['conflict'])) {
      throw new ApiException('通知状态已被其他操作更新, 请刷新后重试', 409);
    }
    return success([
      'receiptId' => $notificationId,
      'read' => (bool) $post['read'],
      'stateRevision' => (int) $result['stateRevision'],
      'unreadCount' => NotificationService::unreadCount((int) $request->uid),
      'serverTime' => time() * 1000,
    ]);
  }

  /**
   * 全部已读（readThroughToken 上界内, requestId 幂等）
   */
  #[OA\Post(
    path: '/api/notifications/read-all',
    operationId: 'notificationReadAll',
    summary: '按令牌上界全部已读',
    tags: ['通知'],
    security: [['sessionAuth' => []]],
    description: '令牌绑定用户与用途；requestId 的幂等结果保留十分钟。',
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\JsonContent(
          ref: '#/components/schemas/NotificationReadAllRequest',
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/NotificationReadAllRequest',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          ref: '#/components/schemas/NotificationReadAllResponse',
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function read_all(Request $request): Response
  {
    $post = $request->post();
    if (
      empty($post) ||
      hasMissingKeys($post, ['requestId', 'readThroughToken'])
    ) {
      throw new ApiException('参数不匹配');
    }
    return success(
      NotificationService::readAll(
        (int) $request->uid,
        (string) $post['requestId'],
        (string) $post['readThroughToken'],
      ),
    );
  }

  /**
   * 清理预览（快照 10 分钟内有效）
   */
  #[OA\Post(
    path: '/api/notifications/clear-preview',
    operationId: 'notificationClearPreview',
    summary: '生成十分钟清理快照',
    tags: ['通知'],
    security: [['sessionAuth' => []]],
    description: '快照绑定用户、批次、上界及数量，十分钟有效。',
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          ref: '#/components/schemas/NotificationClearPreviewResponse',
        ),
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function clear_preview(Request $request): Response
  {
    return success(NotificationService::clearPreview((int) $request->uid));
  }

  /**
   * 执行清理（不可恢复, requestId 幂等）
   */
  #[OA\Post(
    path: '/api/notifications/clear-all',
    operationId: 'notificationClearAll',
    summary: '按快照清理通知',
    tags: ['通知'],
    security: [['sessionAuth' => []]],
    description: '令牌绑定用户与用途；requestId 的幂等结果保留十分钟。',
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\JsonContent(
          ref: '#/components/schemas/NotificationClearAllRequest',
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/NotificationClearAllRequest',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          ref: '#/components/schemas/NotificationClearAllResponse',
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function clear_all(Request $request): Response
  {
    $post = $request->post();
    if (
      empty($post) ||
      hasMissingKeys($post, ['requestId', 'clearSnapshotToken'])
    ) {
      throw new ApiException('参数不匹配');
    }
    return success(
      NotificationService::clearAll(
        (int) $request->uid,
        (string) $post['requestId'],
        (string) $post['clearSnapshotToken'],
      ),
    );
  }
}
