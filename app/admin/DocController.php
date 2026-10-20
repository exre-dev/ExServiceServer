<?php

namespace app\admin;

use OpenApi\Attributes as OA;

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

  #[OA\Get(
    path: '/api/admin/doc-categories',
    operationId: 'adminDocCategories',
    summary: '平台文档分类',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
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
                    ref: '#/components/schemas/AdminDocCategory',
                  ),
                ),
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
  public function categories(Request $request): Response
  {
    $list = DocCategoryModel::where('owner_type', 'platform')
      ->orderBy('sort')
      ->orderBy('id')
      ->get();
    return success(['list' => $list]);
  }

  #[OA\Post(
    path: '/api/admin/doc-categories',
    operationId: 'adminDocCategoryCreate',
    summary: '创建平台文档分类',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(property: 'name', type: 'string', minLength: 1),
          new OA\Property(property: 'sort', type: 'integer', default: 0),
        ],
        required: ['name'],
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
              ref: '#/components/schemas/AdminId',
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
  public function category_create(Request $request): Response
  {
    $name = trim((string) $request->post('name', ''));
    if ($name === '') {
      throw new ApiException('分类名必填');
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
    return success(['id' => $cat->id], '分类已创建');
  }

  #[OA\Delete(
    path: '/api/admin/doc-categories/{id}',
    operationId: 'adminDocCategoryDelete',
    summary: '删除平台文档分类',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。删除后其下文档移至未分类；分类不存在也返回成功。',
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
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function category_delete(Request $request, int $id): Response
  {
    DocCategoryModel::where('owner_type', 'platform')
      ->where('id', $id)
      ->delete();
    AuditService::log((int) $request->uid, 'platform', 'doc.category_delete', [
      'target_type' => 'doc_category',
      'target_id' => $id,
    ]);
    return success([], '分类已删除（其下文档移至未分类）');
  }

  // ==================== 文档 ====================

  #[OA\Get(
    path: '/api/admin/docs',
    operationId: 'adminDocIndex',
    summary: '平台文档列表',
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
        name: 'status',
        in: 'query',
        required: false,
        description: '非空时按状态筛选',
        schema: new OA\Schema(type: 'string', enum: ['draft', 'published']),
      ),
      new OA\Parameter(
        name: 'category_id',
        in: 'query',
        required: false,
        description: '大于0时筛选分类',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'keyword',
        in: 'query',
        required: false,
        description: '关键词，去除首尾空白后模糊搜索',
        schema: new OA\Schema(type: 'string'),
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
                  items: new OA\Items(ref: '#/components/schemas/AdminDoc'),
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
        $sq
          ->where('title', 'like', "%{$keyword}%")
          ->orWhere('slug', 'like', "%{$keyword}%");
      });
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    return success(['list' => $pager->items(), 'total' => $pager->total()]);
  }

  #[OA\Post(
    path: '/api/admin/docs',
    operationId: 'adminDocCreate',
    summary: '创建平台文档',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。owner_type 固定为 platform。servers/tags 可见性必须至少包含一个有效空间/标签。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(property: 'title', type: 'string', minLength: 1),
          new OA\Property(
            property: 'slug',
            type: 'string',
            description: '转为小写；无效、空或重复时自动生成唯一 doc- 前缀标识',
          ),
          new OA\Property(property: 'content_md', type: 'string'),
          new OA\Property(
            property: 'category_id',
            type: 'integer',
            description: '0 表示未分类',
          ),
          new OA\Property(
            property: 'visibility',
            type: 'string',
            enum: ['public', 'login', 'servers', 'tags'],
            default: 'public',
          ),
          new OA\Property(
            property: 'visible_servers',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            description: 'visibility=servers 时至少一个有效空间 ID',
          ),
          new OA\Property(
            property: 'visible_tags',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            description: 'visibility=tags 时至少一个有效标签 ID',
          ),
          new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['draft', 'published'],
            default: 'draft',
            description: '创建时非法值回退 draft',
          ),
        ],
        required: ['title'],
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
              ref: '#/components/schemas/AdminId',
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
  public function create(Request $request): Response
  {
    $post = $request->post();
    if (hasMissingKeys($post, ['title'])) {
      throw new ApiException('参数不匹配');
    }
    $title = trim((string) $post['title']);
    if ($title === '') {
      throw new ApiException('标题必填');
    }

    $doc = new DocModel();
    $doc->owner_type = 'platform';
    $doc->server_id = null;
    $doc->category_id = (int) ($post['category_id'] ?? 0) ?: null;
    $doc->title = $title;
    $doc->slug = $this->uniqueSlug((string) ($post['slug'] ?? ''));
    $doc->content_md = (string) ($post['content_md'] ?? '');
    [
      $doc->visibility,
      $doc->visible_servers,
      $doc->visible_tags,
    ] = $this->resolveVisibility($post);
    $doc->status = in_array($post['status'] ?? '', ['draft', 'published'], true)
      ? $post['status']
      : 'draft';
    $doc->author_user_id = (int) $request->uid;
    $doc->save();

    AuditService::log((int) $request->uid, 'platform', 'doc.create', [
      'target_type' => 'doc',
      'target_id' => $doc->id,
      'detail' => ['slug' => $doc->slug],
    ]);
    return success(['id' => $doc->id], '文档已创建');
  }

  #[OA\Put(
    path: '/api/admin/docs/{id}',
    operationId: 'adminDocUpdate',
    summary: '更新平台文档',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。只更新提供的字段；有字段变动时版本号加1；可见性列表先过滤无效 ID，再校验非空。',
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
      required: false,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(
            property: 'title',
            type: 'string',
            description: '空标题忽略',
          ),
          new OA\Property(
            property: 'slug',
            type: 'string',
            description: '无效或冲突时自动生成唯一标识',
          ),
          new OA\Property(property: 'content_md', type: 'string'),
          new OA\Property(
            property: 'category_id',
            type: 'integer',
            description: '0 表示未分类',
          ),
          new OA\Property(
            property: 'visibility',
            type: 'string',
            enum: ['public', 'login', 'servers', 'tags'],
          ),
          new OA\Property(
            property: 'visible_servers',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            description: 'servers 可见性至少包含一个有效 ID',
          ),
          new OA\Property(
            property: 'visible_tags',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            description: 'tags 可见性至少包含一个有效 ID',
          ),
        ],
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
              ref: '#/components/schemas/AdminVersion',
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
  public function update(Request $request, int $id): Response
  {
    $doc = DocModel::where('owner_type', 'platform')->find($id);
    if (!$doc) {
      throw new ApiException('文档不存在');
    }

    $post = $request->post();
    $dirty = false;
    if (
      array_key_exists('title', $post) &&
      trim((string) $post['title']) !== ''
    ) {
      $doc->title = trim((string) $post['title']);
      $dirty = true;
    }
    if (
      array_key_exists('slug', $post) &&
      (string) $post['slug'] !== $doc->slug
    ) {
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
    if (
      array_key_exists('visibility', $post) ||
      array_key_exists('visible_servers', $post) ||
      array_key_exists('visible_tags', $post)
    ) {
      [
        $doc->visibility,
        $doc->visible_servers,
        $doc->visible_tags,
      ] = $this->resolveVisibility(
        $post + [
          'visibility' => $doc->visibility,
          'visible_servers' => $doc->visible_servers ?? [],
          'visible_tags' => $doc->visible_tags ?? [],
        ],
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

  #[OA\Post(
    path: '/api/admin/docs/{id}/status',
    operationId: 'adminDocStatus',
    summary: '发布或撤下平台文档',
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
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(
            property: 'status',
            type: 'string',
            enum: ['draft', 'published'],
          ),
        ],
        required: ['status'],
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
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function status(Request $request, int $id): Response
  {
    $doc = DocModel::where('owner_type', 'platform')->find($id);
    if (!$doc) {
      throw new ApiException('文档不存在');
    }
    $status = (string) $request->post('status', '');
    if (!in_array($status, ['draft', 'published'], true)) {
      throw new ApiException('非法状态值');
    }
    $doc->status = $status;
    $doc->save();

    AuditService::log((int) $request->uid, 'platform', 'doc.status', [
      'target_type' => 'doc',
      'target_id' => $id,
      'detail' => ['status' => $status],
    ]);
    return success(
      [],
      $status === 'published' ? '已发布（对外可见）' : '已转为草稿',
    );
  }

  #[OA\Delete(
    path: '/api/admin/docs/{id}',
    operationId: 'adminDocDelete',
    summary: '删除平台文档',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。限定 platform 归属；文档不存在也返回成功。',
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
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
  )]
  public function delete(Request $request, int $id): Response
  {
    DocModel::where('owner_type', 'platform')->where('id', $id)->delete();
    AuditService::log((int) $request->uid, 'platform', 'doc.delete', [
      'target_type' => 'doc',
      'target_id' => $id,
    ]);
    return success([], '文档已删除');
  }

  /** 可见性解析与校验: [visibility, visible_servers, visible_tags] */
  private function resolveVisibility(array $post): array
  {
    $visibility = (string) ($post['visibility'] ?? 'public');
    if (!in_array($visibility, self::VISIBILITIES, true)) {
      throw new ApiException('visibility 为 public/login/servers/tags');
    }

    $servers = array_map('intval', (array) ($post['visible_servers'] ?? []));
    if ($servers) {
      $valid = \app\common\model\ServerAccountModel::whereIn('id', $servers)
        ->pluck('id')
        ->all();
      $servers = array_values(array_intersect($servers, $valid));
    }
    if ($visibility === 'servers' && !$servers) {
      throw new ApiException('指定空间成员可见时至少选择一个空间');
    }

    $tags = array_map('intval', (array) ($post['visible_tags'] ?? []));
    if ($tags) {
      $validTags = \app\common\model\TagModel::whereIn('id', $tags)
        ->pluck('id')
        ->all();
      $tags = array_values(array_intersect($tags, $validTags));
    }
    if ($visibility === 'tags' && !$tags) {
      throw new ApiException('指定标签可见时至少选择一个标签');
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
    while (
      DocModel::where('slug', $slug)->where('id', '!=', $exceptId)->exists()
    ) {
      $slug = 'doc-' . bin2hex(random_bytes(4));
    }
    return $slug;
  }
}
