<?php

namespace app\server;

use OpenApi\Attributes as OA;

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

  #[OA\Get(
    path: '/api/server/{sid}/doc-categories',
    operationId: 'serverDocCategoryList',
    summary: '空间文档分类',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅本空间 owner_type=server 分类，按 sort、ID 升序。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/ContentCategoriesData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
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

  #[OA\Post(
    path: '/api/server/{sid}/doc-categories',
    operationId: 'serverDocCategoryCreate',
    summary: '创建空间文档分类',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。分类名 trim 后必填；sort 默认 0。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\JsonContent(ref: '#/components/schemas/ContentCategoryInput'),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ContentCategoryInput',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/ServerCreatedIdData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function category_create(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);
    $name = trim((string) $request->post('name', ''));
    if ($name === '') {
      throw new ApiException('分类名必填');
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
    return success(['id' => $cat->id], '分类已创建');
  }

  #[OA\Delete(
    path: '/api/server/{sid}/doc-categories/{id}',
    operationId: 'serverDocCategoryDelete',
    summary: '删除空间文档分类',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅删除本空间分类；不存在也成功，数据库外键将关联文档 category_id 置空。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '分类 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function category_delete(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);
    DocCategoryModel::where('owner_type', 'server')
      ->where('server_id', $sid)
      ->where('id', $id)
      ->delete();
    AuditService::log((int) $request->uid, 'server', 'doc.category_delete', [
      'server_id' => $sid,
      'target_type' => 'doc_category',
      'target_id' => $id,
    ]);
    return success([], '分类已删除');
  }

  // ==================== 文档 ====================

  #[OA\Get(
    path: '/api/server/{sid}/docs',
    operationId: 'serverDocList',
    summary: '本空间文档列表',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅 owner_type=server 且 server_id 为当前空间；按 ID 倒序，含草稿和完整正文。keyword 匹配 title 或 slug。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'page',
        in: 'query',
        required: false,
        description: '小于 1 归一为 1',
        schema: new OA\Schema(type: 'integer', default: 1),
      ),
      new OA\Parameter(
        name: 'page_size',
        in: 'query',
        required: false,
        description: '归一为 1..50',
        schema: new OA\Schema(type: 'integer', default: 10),
      ),
      new OA\Parameter(
        name: 'status',
        in: 'query',
        required: false,
        description: '非空时精确过滤状态',
        schema: new OA\Schema(type: 'string'),
      ),
      new OA\Parameter(
        name: 'category_id',
        in: 'query',
        required: false,
        description: '大于 0 时过滤分类',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'keyword',
        in: 'query',
        required: false,
        description: '标题或 slug 模糊匹配',
        schema: new OA\Schema(type: 'string'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/ContentManagedDocsData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
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
        $sq
          ->where('title', 'like', "%{$keyword}%")
          ->orWhere('slug', 'like', "%{$keyword}%");
      });
    }

    $pager = $q->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);
    return success(['list' => $pager->items(), 'total' => $pager->total()]);
  }

  #[OA\Post(
    path: '/api/server/{sid}/docs',
    operationId: 'serverDocCreate',
    summary: '创建空间文档',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。标题必填；visibility 默认为 members。slug 无效或冲突自动生成；roles 至少一个有效角色。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\JsonContent(
          ref: '#/components/schemas/ContentServerDocCreateInput',
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ContentServerDocCreateInput',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/ServerCreatedIdData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function create(Request $request, int $sid): Response
  {
    ServerMember::assertManager($request);

    $post = $request->post();
    $title = trim((string) ($post['title'] ?? ''));
    if ($title === '') {
      throw new ApiException('标题必填');
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
    $doc->status = in_array($post['status'] ?? '', ['draft', 'published'], true)
      ? $post['status']
      : 'draft';
    $doc->author_user_id = (int) $request->uid;
    $doc->save();

    AuditService::log((int) $request->uid, 'server', 'doc.create', [
      'server_id' => $sid,
      'target_type' => 'doc',
      'target_id' => $doc->id,
      'detail' => ['slug' => $doc->slug, 'visibility' => $visibility],
    ]);
    return success(['id' => $doc->id], '文档已创建');
  }

  #[OA\Put(
    path: '/api/server/{sid}/docs/{id}',
    operationId: 'serverDocUpdate',
    summary: '编辑空间文档',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅查本空间 owner_type=server 文档，不存在返回 400；有效编辑 version+1，无有效编辑返回原 version；发布状态单独修改。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '文档 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: false,
      content: [
        new OA\JsonContent(
          ref: '#/components/schemas/ContentServerDocUpdateInput',
        ),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ContentServerDocUpdateInput',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/ServerVersionData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function update(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $doc = DocModel::where('owner_type', 'server')
      ->where('server_id', $sid)
      ->find($id);
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
      array_key_exists('visible_roles', $post)
    ) {
      [$visibility, $visibleRoles] = $this->resolveVisibility(
        $post + [
          'visibility' => $doc->visibility,
          'visible_roles' => $doc->visible_roles,
        ],
      );
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

  #[OA\Post(
    path: '/api/server/{sid}/docs/{id}/status',
    operationId: 'serverDocStatus',
    summary: '修改文档发布状态',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅本空间文档；status 必须为 draft/published；不增加版本号。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '文档 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: [
        new OA\JsonContent(ref: '#/components/schemas/ContentDocStatusInput'),
        new OA\MediaType(
          mediaType: 'application/x-www-form-urlencoded',
          schema: new OA\Schema(
            ref: '#/components/schemas/ContentDocStatusInput',
          ),
        ),
      ],
    ),
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function status(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);

    $doc = DocModel::where('owner_type', 'server')
      ->where('server_id', $sid)
      ->find($id);
    if (!$doc) {
      throw new ApiException('文档不存在');
    }
    $status = (string) $request->post('status', '');
    if (!in_array($status, ['draft', 'published'], true)) {
      throw new ApiException('非法状态值');
    }
    $doc->status = $status;
    $doc->save();

    AuditService::log((int) $request->uid, 'server', 'doc.status', [
      'server_id' => $sid,
      'target_type' => 'doc',
      'target_id' => $id,
      'detail' => ['status' => $status],
    ]);
    return success([], $status === 'published' ? '已发布' : '已转为草稿');
  }

  #[OA\Delete(
    path: '/api/server/{sid}/docs/{id}',
    operationId: 'serverDocDelete',
    summary: '删除空间文档',
    description: '需要当前空间 active 成员身份及 owner/admin 权限；空间角色/归属/业务检查失败按当前实现返回 400。仅删除本空间 owner_type=server 文档；目标不存在也返回成功。',
    tags: ['空间管理'],
    security: [['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'sid',
        in: 'path',
        required: true,
        description: '空间 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: '文档 ID',
        schema: new OA\Schema(type: 'integer'),
      ),
    ],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              type: 'array',
              maxItems: 0,
              items: new OA\Items(type: 'string'),
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 401,
        description: '登录态缺失、无效、过期、撤销或用户不存在',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 403,
        description: '平台封禁或 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 503,
        ref: '#/components/responses/SetupRequired',
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function delete(Request $request, int $sid, int $id): Response
  {
    ServerMember::assertManager($request);
    DocModel::where('owner_type', 'server')
      ->where('server_id', $sid)
      ->where('id', $id)
      ->delete();
    AuditService::log((int) $request->uid, 'server', 'doc.delete', [
      'server_id' => $sid,
      'target_type' => 'doc',
      'target_id' => $id,
    ]);
    return success([], '文档已删除');
  }

  /** 可见性解析与校验 */
  private function resolveVisibility(array $post): array
  {
    $visibility = (string) ($post['visibility'] ?? 'members');
    if (!in_array($visibility, self::VISIBILITIES, true)) {
      throw new ApiException('visibility 为 public/members/roles');
    }
    $roles = $post['visible_roles'] ?? [];
    if (!is_array($roles)) {
      $roles = [];
    }
    $roles = array_values(
      array_intersect($roles, ['owner', 'admin', 'member']),
    );
    if ($visibility === 'roles' && !$roles) {
      throw new ApiException('指定角色可见时至少选择一个角色');
    }
    return [$visibility, $visibility === 'roles' ? $roles : null];
  }

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
