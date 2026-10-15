<?php

namespace app\admin;

use app\common\exception\ApiException;
use app\common\model\DocCategoryModel;
use app\common\model\DocModel;
use app\common\services\AuditService;

use support\Request;
use support\Response;

/**
 * 平台管理端-文档管理（owner_type=platform）
 * 可见性: public 公开 / login 仅登录用户 / servers 指定空间成员(用户组) / tags 指定用户标签
 */
class DocController
{
  private const VISIBILITIES = ['public', 'login', 'servers', 'tags'];

  // ==================== 分类 ====================

  public function categories(Request $request): Response
  {
    $list = DocCategoryModel::where('owner_type', 'platform')
      ->orderBy('sort')
      ->orderBy('id')
      ->get();
    return success(['list' => $list]);
  }

  public function category_create(Request $request): Response
  {
    $name = trim((string) $request->post('name', ''));
    if ($name === '') {
      throw new ApiException("分类名必填");
    }
    $cat = new DocCategoryModel();
    $cat->owner_type = 'platform';
    $cat->name = $name;
    $cat->sort = (int) $request->post('sort', 0);
    $cat->save();

    AuditService::log((int) $request->uid, 'platform', 'doc.category_create', [
      'target_type' => 'doc_category',
      'target_id' => $cat->id,
      'detail' => ['name' => $name],
    ]);
    return success(['id' => $cat->id], "分类已创建");
  }

  public function category_delete(Request $request, int $id): Response
  {
    DocCategoryModel::where('owner_type', 'platform')->where('id', $id)->delete();
    AuditService::log((int) $request->uid, 'platform', 'doc.category_delete', [
      'target_type' => 'doc_category',
      'target_id' => $id,
    ]);
    return success([], "分类已删除（其下文档移至未分类）");
  }

  // ==================== 文档 ====================

  public function index(Request $request): Response
  {
    $page = max(1, (int) $request->get('page', 1));
    $pageSize = min(50, max(1, (int) $request->get('page_size', 10)));
    $status = (string) $request->get('status', '');
    $categoryId = (int) $request->get('category_id', 0);
    $keyword = trim((string) $request->get('keyword', ''));

    $q = DocModel::where('owner_type', 'platform');
    if ($status !== '') {
      $q->where('status', $status);
    }
    if ($categoryId > 0) {
      $q->where('category_id', $categoryId);
    }
    if ($keyword !== '') {
      $q->where(function ($sq) use ($keyword) {
        $sq->where('title', 'like', "%{$keyword}%")->orWhere('slug', 'like', "%{$keyword}%");
      });
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    return success(['list' => $pager->items(), 'total' => $pager->total()]);
  }

  public function create(Request $request): Response
  {
    $post = $request->post();
    if (hasMissingKeys($post, ['title'])) {
      throw new ApiException("参数不匹配");
    }
    $title = trim((string) $post['title']);
    if ($title === '') {
      throw new ApiException("标题必填");
    }

    $doc = new DocModel();
    $doc->owner_type = 'platform';
    $doc->server_id = null;
    $doc->category_id = (int) ($post['category_id'] ?? 0) ?: null;
    $doc->title = $title;
    $doc->slug = $this->uniqueSlug((string) ($post['slug'] ?? ''));
    $doc->content_md = (string) ($post['content_md'] ?? '');
    [$doc->visibility, $doc->visible_servers, $doc->visible_tags] = $this->resolveVisibility($post);
    $doc->status = in_array(($post['status'] ?? ''), ['draft', 'published'], true) ? $post['status'] : 'draft';
    $doc->author_user_id = (int) $request->uid;
    $doc->save();

    AuditService::log((int) $request->uid, 'platform', 'doc.create', [
      'target_type' => 'doc',
      'target_id' => $doc->id,
      'detail' => ['slug' => $doc->slug],
    ]);
    return success(['id' => $doc->id], "文档已创建");
  }

  public function update(Request $request, int $id): Response
  {
    $doc = DocModel::where('owner_type', 'platform')->find($id);
    if (!$doc) {
      throw new ApiException("文档不存在");
    }

    $post = $request->post();
    $dirty = false;
    if (array_key_exists('title', $post) && trim((string) $post['title']) !== '') {
      $doc->title = trim((string) $post['title']);
      $dirty = true;
    }
    if (array_key_exists('slug', $post) && (string) $post['slug'] !== $doc->slug) {
      $doc->slug = $this->uniqueSlug((string) $post['slug'], $id);
      $dirty = true;
    }
    if (array_key_exists('content_md', $post)) {
      $doc->content_md = (string) $post['content_md'];
      $dirty = true;
    }
    if (array_key_exists('category_id', $post)) {
      $doc->category_id = ((int) $post['category_id']) ?: null;
      $dirty = true;
    }
    if (array_key_exists('visibility', $post) || array_key_exists('visible_servers', $post) || array_key_exists('visible_tags', $post)) {
      [$doc->visibility, $doc->visible_servers, $doc->visible_tags] = $this->resolveVisibility(
        $post + [
          'visibility' => $doc->visibility,
          'visible_servers' => $doc->visible_servers ?? [],
          'visible_tags' => $doc->visible_tags ?? [],
        ]
      );
      $dirty = true;
    }
    if ($dirty) {
      $doc->version = (int) $doc->version + 1;
      $doc->save();
    }

    AuditService::log((int) $request->uid, 'platform', 'doc.update', [
      'target_type' => 'doc',
      'target_id' => $id,
      'detail' => ['version' => $doc->version],
    ]);
    return success(['version' => $doc->version]);
  }

  public function status(Request $request, int $id): Response
  {
    $doc = DocModel::where('owner_type', 'platform')->find($id);
    if (!$doc) {
      throw new ApiException("文档不存在");
    }
    $status = (string) $request->post('status', '');
    if (!in_array($status, ['draft', 'published'], true)) {
      throw new ApiException("非法状态值");
    }
    $doc->status = $status;
    $doc->save();

    AuditService::log((int) $request->uid, 'platform', 'doc.status', [
      'target_type' => 'doc',
      'target_id' => $id,
      'detail' => ['status' => $status],
    ]);
    return success([], $status === 'published' ? "已发布（对外可见）" : "已转为草稿");
  }

  public function delete(Request $request, int $id): Response
  {
    DocModel::where('owner_type', 'platform')->where('id', $id)->delete();
    AuditService::log((int) $request->uid, 'platform', 'doc.delete', [
      'target_type' => 'doc',
      'target_id' => $id,
    ]);
    return success([], "文档已删除");
  }

  /** 可见性解析与校验: [visibility, visible_servers, visible_tags] */
  private function resolveVisibility(array $post): array
  {
    $visibility = (string) ($post['visibility'] ?? 'public');
    if (!in_array($visibility, self::VISIBILITIES, true)) {
      throw new ApiException("visibility 为 public/login/servers/tags");
    }

    $servers = array_map('intval', (array) ($post['visible_servers'] ?? []));
    if ($servers) {
      $valid = \app\common\model\ServerAccountModel::whereIn('id', $servers)->pluck('id')->all();
      $servers = array_values(array_intersect($servers, $valid));
    }
    if ($visibility === 'servers' && !$servers) {
      throw new ApiException("指定空间成员可见时至少选择一个空间");
    }

    $tags = array_map('intval', (array) ($post['visible_tags'] ?? []));
    if ($tags) {
      $validTags = \app\common\model\TagModel::whereIn('id', $tags)->pluck('id')->all();
      $tags = array_values(array_intersect($tags, $validTags));
    }
    if ($visibility === 'tags' && !$tags) {
      throw new ApiException("指定标签可见时至少选择一个标签");
    }

    return [
      $visibility,
      $visibility === 'servers' ? array_values(array_unique($servers)) : null,
      $visibility === 'tags' ? array_values(array_unique($tags)) : null,
    ];
  }

  /** slug 生成与全局唯一校验 */
  private function uniqueSlug(string $slug, int $exceptId = 0): string
  {
    $slug = strtolower(trim($slug));
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $slug)) {
      $slug = 'doc-' . bin2hex(random_bytes(4));
    }
    while (DocModel::where('slug', $slug)->where('id', '!=', $exceptId)->exists()) {
      $slug = 'doc-' . bin2hex(random_bytes(4));
    }
    return $slug;
  }
}
