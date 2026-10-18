<?php

namespace app\support;

use app\common\exception\ApiException;
use app\common\model\FeedbackAttachmentModel;
use app\common\model\FeedbackModel;
use app\common\services\AuditService;
use app\common\services\StorageService;
use support\Request;
use support\Response;

/**
 * 用户反馈（用户侧, /api/support/feedback/*）
 *
 * 状态机（契约枚举的落地子集）:
 * prepare → receiving（附件声明 status=expected, nextAction=upload|continue）
 * 附件 PUT → uploaded, 全部到位后 nextAction=continue
 * advance(continue) → completed（nextAction=done）; revision 乐观锁。
 * 管理端处理状态在 admin 侧单独维护（admin_status）, 不混入用户枚举。
 */
class FeedbackController
{
  /** 反馈 8 类型（与契约 kind 枚举一致） */
  private const KINDS = ['bug', 'feature', 'asset', 'request', 'content', 'campaign', 'suggestion', 'complaint'];

  /** 标题/正文字节上限（契约 limits） */
  private const USER_CONTENT_BYTES = 65536;

  /**
   * 表单选项（本部署全类型可用, 无动态字段）
   */
  public function options(Request $request): Response
  {
    return success([
      'available' => true,
      'reason' => null,
      'fields' => [],
      'kinds' => array_map(fn ($k) => ['kind' => $k, 'available' => true, 'reason' => null], self::KINDS),
      'actions' => [
        ['action' => 'add', 'available' => true, 'reason' => null],
        ['action' => 'modify', 'available' => true, 'reason' => null],
        ['action' => 'delete', 'available' => true, 'reason' => null],
        ['action' => 'other', 'available' => true, 'reason' => null],
      ],
      'limits' => [
        'titleCodePoints' => 200,
        'userContentBytes' => self::USER_CONTENT_BYTES,
        'fileBytes' => StorageService::MAX_BYTES,
        'files' => 5,
        'totalFileBytes' => 104857600,
        'largeFileInfoBytes' => 65536,
      ],
    ]);
  }

  /**
   * 提交反馈（prepare: 表单快照 + 附件声明; submissionKey 由客户端生成）
   */
  public function prepare(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["submissionKey", "form", "attachments"])) {
      throw new ApiException("参数不匹配");
    }

    $submissionKey = (string) $post['submissionKey'];
    if (!self::isUuid($submissionKey)) {
      throw new ApiException("submissionKey 非法");
    }
    // 幂等: 同 key 重复提交返回已有快照
    $existing = FeedbackModel::where('submission_key', $submissionKey)->where('user_id', $request->uid)->first();
    if ($existing) {
      return success($this->snapshot($existing), "反馈已在收取中");
    }

    $form = $post['form'];
    if (!is_array($form) || !in_array((string) ($form['kind'] ?? ''), self::KINDS, true)) {
      throw new ApiException("反馈类型无效");
    }
    $title = (string) ($form['title'] ?? '');
    if ($title === '' || mb_strlen($title) > 200) {
      throw new ApiException("标题需为 1-200 个字符");
    }
    if (strlen(json_encode($form)) > self::USER_CONTENT_BYTES * 2) {
      throw new ApiException("表单内容过大", 413);
    }

    $attachments = (array) $post['attachments'];
    if (count($attachments) > 5) {
      throw new ApiException("附件最多 5 个", 413);
    }

    $row = new FeedbackModel();
    $row->uuid = self::uuid();
    $row->submission_key = $submissionKey;
    $row->area = in_array((string) $request->get('area', 'development'), ['development', 'community'], true)
      ? (string) $request->get('area', 'development') : 'development';
    $row->kind = (string) $form['kind'];
    $row->user_id = (int) $request->uid;
    $row->form = $form;
    $row->status = 'receiving';
    $row->revision = 0;
    $row->receive_expires_at = date('Y-m-d H:i:s', time() + 86400);
    $row->save();

    $ordinal = 0;
    foreach ($attachments as $decl) {
      if (!is_array($decl) || !isset($decl['originalName'], $decl['contentType'], $decl['byteLength'], $decl['sha256'])) {
        continue;
      }
      $att = new FeedbackAttachmentModel();
      $att->uuid = self::isUuid((string) ($decl['id'] ?? '')) ? (string) $decl['id'] : self::uuid();
      $att->submission_id = $row->id;
      $att->ordinal = $ordinal++;
      $att->original_name = mb_substr((string) $decl['originalName'], 0, 510);
      $att->content_type = mb_substr((string) $decl['contentType'], 0, 127);
      $att->byte_length = (int) $decl['byteLength'];
      $att->sha256 = (string) $decl['sha256'];
      $att->status = 'expected';
      $att->save();
    }

    AuditService::log((int) $request->uid, 'user', 'feedback.prepare', [
      'target_type' => 'feedback',
      'target_id' => $row->uuid,
      'detail' => ['kind' => $row->kind, 'attachments' => count($attachments)],
    ]);

    return success($this->snapshot($row->refresh()), "反馈已受理")->withStatus(201);
  }

  /**
   * 按恢复键查反馈
   */
  public function lookup(Request $request): Response
  {
    $key = (string) $request->get('submissionKey', '');
    $row = FeedbackModel::where('submission_key', $key)->where('user_id', $request->uid)->first();
    if (!$row) {
      throw new ApiException("反馈不存在", 404);
    }
    return success($this->snapshot($row));
  }

  /**
   * 反馈详情
   */
  public function detail(Request $request, string $submissionId): Response
  {
    $row = FeedbackModel::where('uuid', $submissionId)->where('user_id', $request->uid)->first();
    if (!$row) {
      throw new ApiException("反馈不存在", 404);
    }
    return success($this->snapshot($row));
  }

  /**
   * 上传附件（multipart file 字段; sha256/大小须与声明一致）
   */
  public function attachment_upload(Request $request, string $submissionId, string $attachmentId): Response
  {
    $row = FeedbackModel::where('uuid', $submissionId)->where('user_id', $request->uid)->first();
    if (!$row) {
      throw new ApiException("反馈不存在", 404);
    }
    if ($row->status !== 'receiving') {
      throw new ApiException("反馈已完成, 不能再上传附件", 409);
    }
    $att = FeedbackAttachmentModel::where('uuid', $attachmentId)->where('submission_id', $row->id)->first();
    if (!$att) {
      throw new ApiException("附件声明不存在", 404);
    }
    if ($att->status === 'uploaded') {
      return success($this->snapshot($row), "附件已上传");
    }

    $file = $request->file('file');
    if (!$file || !$file->isValid()) {
      throw new ApiException("缺少 file 文件部分");
    }
    if ($file->getSize() > StorageService::MAX_BYTES) {
      throw new ApiException("附件超过大小上限", 413);
    }
    $bytes = (string) file_get_contents($file->getRealPath());
    $actualHash = hash('sha256', $bytes);
    if ($actualHash !== $att->sha256) {
      throw new ApiException("附件内容与声明不一致, 请重试", 409);
    }
    if (strlen($bytes) !== (int) $att->byte_length) {
      throw new ApiException("附件大小与声明不一致, 请重试", 409);
    }

    $storageKey = 'feedback/' . $att->uuid . '/' . rawurlencode($att->original_name);
    StorageService::put($storageKey, $bytes);

    $att->status = 'uploaded';
    $att->storage_key = $storageKey;
    $att->save();
    $row->revision = (int) $row->revision + 1;
    $row->save();

    return success($this->snapshot($row));
  }

  /**
   * 推进（continue: 完成提交; retry: 失败重试重新开放收取）
   */
  public function advance(Request $request, string $submissionId): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["expectedRevision", "intent"])) {
      throw new ApiException("参数不匹配");
    }
    $row = FeedbackModel::where('uuid', $submissionId)->where('user_id', $request->uid)->first();
    if (!$row) {
      throw new ApiException("反馈不存在", 404);
    }
    if ((int) $row->revision !== (int) $post['expectedRevision']) {
      throw new ApiException("反馈状态已变化, 请刷新后重试", 409);
    }

    $intent = (string) $post['intent'];
    if ($intent === 'continue') {
      // 附件必须全部到位
      $missing = FeedbackAttachmentModel::where('submission_id', $row->id)->where('status', 'expected')->count();
      if ($missing > 0) {
        throw new ApiException("还有 {$missing} 个附件未上传");
      }
      $row->status = 'completed';
      $row->revision = (int) $row->revision + 1;
      $row->save();

      AuditService::log((int) $request->uid, 'user', 'feedback.complete', [
        'target_type' => 'feedback',
        'target_id' => $row->uuid,
      ]);
    } elseif ($intent === 'retry') {
      // receiving 保持不变, 重新返回当前快照（客户端据此续传）
    } else {
      throw new ApiException("intent 只支持 continue/retry");
    }

    return success($this->snapshot($row));
  }

  // ==================== 输出 ====================

  /**
   * 契约快照（FeedbackSnapshot）
   */
  private function snapshot(FeedbackModel $row): array
  {
    $atts = FeedbackAttachmentModel::where('submission_id', $row->id)->orderBy('ordinal')->get();
    $pendingCount = $atts->where('status', 'expected')->count();
    $completed = $row->status === 'completed';

    return [
      'id' => $row->uuid,
      'submissionKey' => $row->submission_key,
      'revision' => (int) $row->revision,
      'form' => $row->form,
      'createdAt' => strtotime((string) $row->created_time) ?: 0,
      'receiveExpiresAt' => $row->receive_expires_at ? (strtotime((string) $row->receive_expires_at) ?: 0) : 0,
      'status' => $completed ? 'completed' : 'receiving',
      'nextAction' => $completed ? 'done' : ($pendingCount > 0 ? 'upload' : 'continue'),
      'taskId' => null,
      'taskUrl' => null,
      'failure' => null,
      'attachments' => array_map(fn ($a) => [
        'id' => $a->uuid,
        'ordinal' => (int) $a->ordinal,
        'originalName' => $a->original_name,
        'contentType' => $a->content_type,
        'byteLength' => (int) $a->byte_length,
        'sha256' => $a->sha256,
        'status' => $a->status,
        'remoteAttachmentId' => null,
      ], $atts->all()),
    ];
  }

  private static function uuid(): string
  {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
  }

  private static function isUuid(string $value): bool
  {
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value);
  }
}
