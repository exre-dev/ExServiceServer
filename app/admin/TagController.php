<?php

namespace app\admin;

use app\common\exception\ApiException;
use app\common\model\TagModel;
use app\common\model\UserModel;
use app\common\model\UserTagModel;
use app\common\services\AuditService;

use support\Request;
use support\Response;

/**
 * 平台管理端-用户标签（字典管理 + 用户打标, 文档可见性 tags 级别使用）
 */
class TagController
{
  /**
   * 标签列表（含每个标签的用户数）
   */
  public function index(Request $request): Response
  {
    $list = TagModel::withCount('users as user_count')->orderBy('id')->get();
    return success(['list' => $list]);
  }

  /**
   * 创建标签
   */
  public function create(Request $request): Response
  {
    $name = trim((string) $request->post('name', ''));
    if ($name === '' || mb_strlen($name) > 32) {
      throw new ApiException("标签名为 1-32 个字符");
    }
    if (TagModel::where('name', $name)->exists()) {
      throw new ApiException("标签已存在");
    }
    $tag = new TagModel();
    $tag->name = $name;
    $tag->note = (string) ($request->post('note', '') ?? '');
    $tag->save();

    AuditService::log((int) $request->uid, 'platform', 'tag.create', [
      'target_type' => 'tag',
      'target_id' => $tag->id,
      'detail' => ['name' => $name],
    ]);
    return success(['id' => $tag->id], "标签已创建");
  }

  /**
   * 删除标签（文档可见性引用会同步失效）
   */
  public function delete(Request $request, int $id): Response
  {
    TagModel::where('id', $id)->delete();
    AuditService::log((int) $request->uid, 'platform', 'tag.delete', [
      'target_type' => 'tag',
      'target_id' => $id,
    ]);
    return success([], "标签已删除");
  }

  /**
   * 设置用户标签（全量替换）
   */
  public function set_user_tags(Request $request, int $id): Response
  {
    $user = UserModel::find($id);
    if (!$user) {
      throw new ApiException("用户不存在");
    }
    $tagIds = $request->post('tag_ids', []);
    if (!is_array($tagIds)) {
      $tagIds = [];
    }
    $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
    if ($tagIds) {
      $valid = TagModel::whereIn('id', $tagIds)->pluck('id')->all();
      $tagIds = array_values(array_intersect($tagIds, $valid));
    }

    UserTagModel::where('user_id', $id)->delete();
    foreach ($tagIds as $tid) {
      $row = new UserTagModel();
      $row->user_id = $id;
      $row->tag_id = $tid;
      $row->created_time = date('Y-m-d H:i:s');
      $row->save();
    }

    AuditService::log((int) $request->uid, 'platform', 'user.tags', [
      'target_type' => 'user',
      'target_id' => $id,
      'detail' => ['tag_ids' => $tagIds],
    ]);
    return success(['tag_ids' => $tagIds], "标签已保存");
  }
}
