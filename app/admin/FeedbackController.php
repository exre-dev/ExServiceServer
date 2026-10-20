<?php

namespace app\admin;

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
