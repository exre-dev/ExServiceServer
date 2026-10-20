<?php

namespace app\admin;

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
