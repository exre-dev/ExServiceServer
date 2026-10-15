<?php

namespace app\server;

use app\common\exception\ApiException;
use app\common\middleware\ServerMember;
use app\common\model\DocCategoryModel;
use app\common\model\DocModel;
use app\common\services\AuditService;

use support\Request;
use support\Response;

/**
 * 空间管理端-空间文档（owner_type=server, 三级可见性 public/members/roles）
 */
class DocController
{
  private const VISIBILITIES = ['public', 'members', 'roles'];

  // ==================== 分类 ====================

  public function categories(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);
    $list = DocCategoryModel::where('owner_type', 'server')
      ->where('server_id', $sid)
      ->orderBy('sort')
      ->orderBy('id')
      ->get();
    return success(['list' => $list]);
  }

  public function category_create(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);
    $name = trim((string) $request->post('name', ''));
    if ($name === '') {
      throw new ApiException("分类名必填");
    }
    $cat = new DocCategoryModel();
    $cat->owner_type = 'server';
    $cat->server_id = $sid;
    $cat->name = $name;
    $cat->sort = (int) $request->post('sort', 0);
    $cat->save();

    AuditService::log((int) $request->uid, 'server', 'doc.category_create', [
      'server_id' => $sid,
      'target_type' => 'doc_category',
      'target_id' => $cat->id,
    ]);
    return success(['id' => $cat->id], "分类已创建");
  }

  public function category_delete(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);
    DocCategoryModel::where('owner_type', 'server')->where('server_id', $sid)->where('id', $id)->delete();
    AuditService::log((int) $request->uid, 'server', 'doc.category_delete', [
      'server_id' => $sid,
      'target_type' => 'doc_category',
      'target_id' => $id,
    ]);
    return success([], "分类已删除");
  }

  // ==================== 文档 ====================

  public function index(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $page = max(1, (int) $request->get('page', 1));
    $pageSize = min(50, max(1, (int) $request->get('page_size', 10)));
    $status = (string) $request->get('status', '');
    $categoryId = (int) $request->get('category_id', 0);
    $keyword = trim((string) $request->get('keyword', ''));

    $q = DocModel::where('owner_type', 'server')->where('server_id', $sid);
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

  public function create(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $post = $request->post();
    $title = trim((string) ($post['title'] ?? ''));
    if ($title === '') {
      throw new ApiException("标题必填");
    }

    [$visibility, $visibleRoles] = $this->resolveVisibility($post);

    $doc = new DocModel();
    $doc->owner_type = 'server';
    $doc->server_id = $sid;
    $doc->category_id = (int) ($post['category_id'] ?? 0) ?: null;
    $doc->title = $title;
    $doc->slug = $this->uniqueSlug((string) ($post['slug'] ?? ''));
    $doc->content_md = (string) ($post['content_md'] ?? '');
    $doc->visibility = $visibility;
    $doc->visible_roles = $visibleRoles;
    $doc->status = in_array(($post['status'] ?? ''), ['draft', 'published'], true) ? $post['status'] : 'draft';
    $doc->author_user_id = (int) $request->uid;
    $doc->save();

    AuditService::log((int) $request->uid, 'server', 'doc.create', [
      'server_id' => $sid,
      'target_type' => 'doc',
      'target_id' => $doc->id,
      'detail' => ['slug' => $doc->slug, 'visibility' => $visibility],
    ]);
    return success(['id' => $doc->id], "文档已创建");
  }

  public function update(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $doc = DocModel::where('owner_type', 'server')->where('server_id', $sid)->find($id);
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
    if (array_key_exists('visibility', $post) || array_key_exists('visible_roles', $post)) {
      [$visibility, $visibleRoles] = $this->resolveVisibility($post + ['visibility' => $doc->visibility, 'visible_roles' => $doc->visible_roles]);
      $doc->visibility = $visibility;
      $doc->visible_roles = $visibleRoles;
      $dirty = true;
    }
    if ($dirty) {
      $doc->version = (int) $doc->version + 1;
      $doc->save();
    }

    AuditService::log((int) $request->uid, 'server', 'doc.update', [
      'server_id' => $sid,
      'target_type' => 'doc',
      'target_id' => $id,
      'detail' => ['version' => $doc->version],
    ]);
    return success(['version' => $doc->version]);
  }

  public function status(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $doc = DocModel::where('owner_type', 'server')->where('server_id', $sid)->find($id);
    if (!$doc) {
      throw new ApiException("文档不存在");
    }
    $status = (string) $request->post('status', '');
    if (!in_array($status, ['draft', 'published'], true)) {
      throw new ApiException("非法状态值");
    }
    $doc->status = $status;
    $doc->save();

    AuditService::log((int) $request->uid, 'server', 'doc.status', [
      'server_id' => $sid,
      'target_type' => 'doc',
      'target_id' => $id,
      'detail' => ['status' => $status],
    ]);
    return success([], $status === 'published' ? "已发布" : "已转为草稿");
  }

  public function delete(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);
    DocModel::where('owner_type', 'server')->where('server_id', $sid)->where('id', $id)->delete();
    AuditService::log((int) $request->uid, 'server', 'doc.delete', [
      'server_id' => $sid,
      'target_type' => 'doc',
      'target_id' => $id,
    ]);
    return success([], "文档已删除");
  }

  /** 可见性解析与校验 */
  private function resolveVisibility(array $post): array
  {
    $visibility = (string) ($post['visibility'] ?? 'members');
    if (!in_array($visibility, self::VISIBILITIES, true)) {
      throw new ApiException("visibility 为 public/members/roles");
    }
    $roles = $post['visible_roles'] ?? [];
    if (!is_array($roles)) {
      $roles = [];
    }
    $roles = array_values(array_intersect($roles, ['owner', 'admin', 'member']));
    if ($visibility === 'roles' && !$roles) {
      throw new ApiException("指定角色可见时至少选择一个角色");
    }
    return [$visibility, $visibility === 'roles' ? $roles : null];
  }

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
