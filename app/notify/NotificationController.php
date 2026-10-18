<?php

namespace app\notify;

use app\common\exception\ApiException;
use app\common\services\NotificationService;
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
  public function index(Request $request): Response
  {
    $view = (string) $request->get('view', '');
    if (!in_array($view, ['unread', 'history'], true)) {
      throw new ApiException("view 参数无效");
    }
    $scope = (string) $request->get('scope', 'all');
    if (!in_array($scope, ['all', 'community', 'personal'], true)) {
      throw new ApiException("scope 参数无效");
    }
    $category = (string) $request->get('category', '');
    if ($category !== '' && !in_array($category, ['announcement', 'personal_update', 'service_notice'], true)) {
      throw new ApiException("category 参数无效");
    }
    $limit = (int) $request->get('limit', 20);
    if ($limit < 1 || $limit > 50) {
      $limit = 20;
    }
    $cursor = $request->get('cursor');

    $data = NotificationService::list((int) $request->uid, $view, $scope, $category, $cursor, $limit);
    $data['readThroughToken'] = NotificationService::summary((int) $request->uid)['readThroughToken'];
    $data['serverTime'] = time();
    return success($data);
  }

  /**
   * 未读摘要（铃铛轮询）
   */
  public function summary(Request $request): Response
  {
    return success(NotificationService::summary((int) $request->uid));
  }

  /**
   * 通知详情（读详情不改变已读状态）
   */
  public function detail(Request $request, string $notificationId): Response
  {
    $detail = NotificationService::detail((int) $request->uid, $notificationId);
    if ($detail === []) {
      throw new ApiException("通知不存在", 404);
    }
    return success($detail);
  }

  /**
   * 单条已读/未读（乐观锁 409）
   */
  public function state(Request $request, string $notificationId): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["read", "expectedRevision"])) {
      throw new ApiException("参数不匹配");
    }
    $result = NotificationService::setState(
      (int) $request->uid,
      $notificationId,
      (bool) $post['read'],
      (int) $post['expectedRevision']
    );
    if (!empty($result['missing'])) {
      throw new ApiException("通知不存在", 404);
    }
    if (!empty($result['conflict'])) {
      throw new ApiException("通知状态已被其他操作更新, 请刷新后重试", 409);
    }
    return success([
      'receiptId' => $notificationId,
      'read' => (bool) $post['read'],
      'stateRevision' => (int) $result['stateRevision'],
      'unreadCount' => NotificationService::unreadCount((int) $request->uid),
      'serverTime' => time(),
    ]);
  }

  /**
   * 全部已读（readThroughToken 上界内, requestId 幂等）
   */
  public function read_all(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["requestId", "readThroughToken"])) {
      throw new ApiException("参数不匹配");
    }
    return success(NotificationService::readAll((int) $request->uid, (string) $post['requestId'], (string) $post['readThroughToken']));
  }

  /**
   * 清理预览（快照 10 分钟内有效）
   */
  public function clear_preview(Request $request): Response
  {
    return success(NotificationService::clearPreview((int) $request->uid));
  }

  /**
   * 执行清理（不可恢复, requestId 幂等）
   */
  public function clear_all(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["requestId", "clearSnapshotToken"])) {
      throw new ApiException("参数不匹配");
    }
    return success(NotificationService::clearAll((int) $request->uid, (string) $post['requestId'], (string) $post['clearSnapshotToken']));
  }
}
