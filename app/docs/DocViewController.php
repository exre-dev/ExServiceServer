<?php

namespace app\docs;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\model\DocModel;
use app\common\model\ServerMemberModel;
use app\common\model\UserModel;

use support\Request;
use support\Response;

/**
 * 文档浏览 API（v1 无独立门户 UI, 供管理端预览与外部调用）
 *
 * GET /api/docs                文档列表（按访问者身份过滤可见性, 列表不吐正文）
 * GET /api/docs/{slug}         文档详情（含 Markdown 正文）
 *
 * 可见性规则:
 *   public  → 任何人（含未登录）
 *   members → 本空间 active 成员
 *   roles   → visible_roles 内角色（owner/admin 恒可见）
 * 平台角色（ops/superadmin）可看全部已发布文档。
 */
class DocViewController
{
  /** 浏览免登录（未登录只可见 public） */
  protected $noNeedLogin = ['index', 'detail'];

  #[OA\Get(
    path: '/api/docs',
    operationId: 'contentDocumentList',
    summary: '可浏览文档列表',
    description: '免登录，可选 sessionAuth Bearer；只有 JWT 和本站会话均有效且用户匹配才识别身份，OAuth 访问令牌及无效令牌按匿名处理。public 任何人可见；ops/superadmin 可查看全部已发布文档；login 需有效登录，servers 需指定空间 active 成员，tags 需标签交集；空间 members 需 active 成员，roles 对 owner/admin 恒可见、普通成员需匹配 visible_roles。只选已发布文档，先按 ID 倒序取前 100 条再进行可见性过滤，因此结果可能少于 100；不返回正文。',
    tags: ['文档浏览'],
    security: [[], ['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'server_id',
        in: 'query',
        required: false,
        description: '大于 0 时筛选所属空间',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'category_id',
        in: 'query',
        required: false,
        description: '大于 0 时筛选分类',
        schema: new OA\Schema(type: 'integer'),
      ),
      new OA\Parameter(
        name: 'keyword',
        in: 'query',
        required: false,
        description: '标题模糊匹配',
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
              ref: '#/components/schemas/ContentBrowseData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        description: 'IP 错误请求限流',
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
  public function index(Request $request): Response
  {
    $serverId = (int) $request->get('server_id', 0);
    $categoryId = (int) $request->get('category_id', 0);
    $keyword = trim((string) $request->get('keyword', ''));

    $q = DocModel::where('status', 'published');
    if ($serverId > 0) {
      $q->where('server_id', $serverId);
    }
    if ($categoryId > 0) {
      $q->where('category_id', $categoryId);
    }
    if ($keyword !== '') {
      $q->where('title', 'like', "%{$keyword}%");
    }

    $docs = $q
      ->orderByDesc('id')
      ->limit(100)
      ->get([
        'id',
        'owner_type',
        'server_id',
        'category_id',
        'title',
        'slug',
        'visibility',
        'visible_roles',
        'visible_servers',
        'visible_tags',
        'version',
        'updated_time',
      ]);

    $viewer = $this->viewerContext($request);
    $list = $docs->filter(fn($d) => $this->canView($d, $viewer))->values();

    return success(['list' => $list]);
  }

  #[OA\Get(
    path: '/api/docs/{slug}',
    operationId: 'contentDocumentDetail',
    summary: '浏览文档正文',
    description: '免登录，可选 sessionAuth Bearer；只有 JWT 和本站会话均有效且用户匹配才识别身份，OAuth 访问令牌及无效令牌按匿名处理。public 任何人可见；ops/superadmin 可查看全部已发布文档；login 需有效登录，servers 需指定空间 active 成员，tags 需标签交集；空间 members 需 active 成员，roles 对 owner/admin 恒可见、普通成员需匹配 visible_roles。查不到或不可见均按当前实现返回 400“文档不存在”，以相同响应避免泄露存在性；成功返回完整模型。',
    tags: ['文档浏览'],
    security: [[], ['sessionAuth' => []]],
    parameters: [
      new OA\Parameter(
        name: 'slug',
        in: 'path',
        required: true,
        description: '文档全局唯一 slug',
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
              ref: '#/components/schemas/ContentDocument',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 400,
        ref: '#/components/responses/BusinessError',
      ),
      new OA\Response(
        response: 403,
        description: 'IP 错误请求限流',
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
  public function detail(Request $request, string $slug): Response
  {
    $doc = DocModel::where('slug', $slug)
      ->where('status', 'published')
      ->first();
    if (!$doc) {
      throw new ApiException('文档不存在');
    }

    $viewer = $this->viewerContext($request);
    if (!$this->canView($doc, $viewer)) {
      // 不泄露存在性
      throw new ApiException('文档不存在');
    }

    return success($doc);
  }

  /**
   * 访问者上下文: uid / 是否平台角色 / server_id → 空间角色 映射
   *
   * 注意: 本控制器 noNeedLogin 跳过了 CheckLogin（匿名可看 public），
   * 已登录用户的身份需要在这里做"可选鉴权"自行识别。
   */
  private function viewerContext(Request $request): array
  {
    $uid = $request->uid ?? null;
    if ($uid === null) {
      // 可选鉴权: 带 Bearer 且 JWT+会话均有效才认定身份
      $token = (string) $request->header('authorization', '');
      if (preg_match('/^bearer\s+(\S+)$/i', $token, $m)) {
        try {
          // 1 = ACCESS_TOKEN（tinywan 2.0 中该常量为 private，只能传字面量）
          $v = \Tinywan\Jwt\JwtToken::verify(1, $m[1]);
          $tUid = (int) ($v['extend']['id'] ?? 0);
          $tSid = (int) ($v['extend']['sid'] ?? 0);
          $sess = \app\common\services\SessionService::check($tSid);
          if (
            $tUid > 0 &&
            $sess &&
            (int) $sess['user_id'] === $tUid &&
            empty($sess['client_id'])
          ) {
            $uid = $tUid;
          }
        } catch (\Throwable $e) {
          // 无效令牌按匿名处理
        }
      }
    }

    if ($uid === null) {
      return ['uid' => null, 'platform' => false, 'roles' => []];
    }

    $platform = false;
    $user = UserModel::find($uid);
    if (
      $user &&
      in_array((string) $user->platform_role, ['superadmin', 'ops'], true)
    ) {
      $platform = true;
    }

    $roles = [];
    $tagIds = [];
    if (!$platform) {
      $roles = ServerMemberModel::where('user_id', $uid)
        ->where('status', 'active')
        ->pluck('role_key', 'server_id')
        ->all();
      $tagIds = \app\common\model\UserTagModel::where('user_id', $uid)
        ->pluck('tag_id')
        ->all();
    }

    return [
      'uid' => (int) $uid,
      'platform' => $platform,
      'roles' => $roles,
      'tags' => $tagIds,
    ];
  }

  /**
   * 单文档可见性判定
   */
  private function canView(DocModel $doc, array $viewer): bool
  {
    if ($doc->visibility === 'public') {
      return true;
    }
    if ($viewer['platform']) {
      return true;
    }
    if ($doc->visibility === 'login') {
      return $viewer['uid'] !== null;
    }
    if ($doc->visibility === 'servers') {
      // 用户组 = 指定空间的任意 active 成员
      $servers = is_array($doc->visible_servers) ? $doc->visible_servers : [];
      foreach ($servers as $sid) {
        if (isset($viewer['roles'][(int) $sid])) {
          return true;
        }
      }
      return false;
    }
    if ($doc->visibility === 'tags') {
      $tags = is_array($doc->visible_tags) ? $doc->visible_tags : [];
      return (bool) array_intersect($tags, $viewer['tags'] ?? []);
    }

    if ($viewer['uid'] === null) {
      return false;
    }

    // members / roles 是空间概念
    $sid = (int) $doc->server_id;
    $role = $viewer['roles'][$sid] ?? null;
    if ($role === null) {
      return false;
    }
    if ($doc->visibility === 'members') {
      return true;
    }
    // roles: owner/admin 恒可见, 否则需在 visible_roles 内
    if (in_array($role, ['owner', 'admin'], true)) {
      return true;
    }
    $allowed = is_array($doc->visible_roles) ? $doc->visible_roles : [];
    return in_array($role, $allowed, true);
  }
}
