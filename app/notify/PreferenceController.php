<?php

namespace app\notify;

use app\common\exception\ApiException;
use app\common\model\NotificationPrefModel;
use support\Request;
use support\Response;

/**
 * 通知偏好（/api/notification-preferences）
 *
 * optional 类别整体替换（非 patch）+ 修订号乐观锁（409）。
 * necessary 类别由 catalog 固定（service_notice），只展示不可写。
 */
class PreferenceController
{
  /** 必要类别（固定, 偏好不可关） */
  private const NECESSARY = ['service_notice'];

  public function index(Request $request): Response
  {
    return success($this->payload((int) $request->uid));
  }

  public function update(Request $request): Response
  {
    $post = $request->post();
    if (empty($post) || hasMissingKeys($post, ["expectedRevision", "optionalCategories"])) {
      throw new ApiException("参数不匹配");
    }
    $cats = $post['optionalCategories'];
    if (!is_array($cats) || !array_key_exists('announcement', $cats) || !array_key_exists('personal_update', $cats)
      || !is_bool($cats['announcement']) || !is_bool($cats['personal_update'])) {
      throw new ApiException("optionalCategories 参数无效");
    }

    $uid = (int) $request->uid;
    $pref = NotificationPrefModel::find($uid);
    if (!$pref) {
      $pref = new NotificationPrefModel();
      $pref->user_id = $uid;
      $pref->revision = 0;
    }
    if ((int) $pref->revision !== (int) $post['expectedRevision']) {
      throw new ApiException("偏好已被其他设备更新, 请刷新后重试", 409);
    }

    $pref->announcement = (bool) $cats['announcement'];
    $pref->personal_update = (bool) $cats['personal_update'];
    $pref->revision = (int) $pref->revision + 1;
    $pref->save();

    return success($this->payload($uid));
  }

  private function payload(int $uid): array
  {
    $pref = NotificationPrefModel::find($uid);
    return [
      'revision' => (int) ($pref->revision ?? 0),
      'optionalCategories' => [
        'announcement' => (bool) ($pref->announcement ?? true),
        'personal_update' => (bool) ($pref->personal_update ?? true),
      ],
      'necessaryCategories' => self::NECESSARY,
      'serverTime' => time(),
    ];
  }
}
