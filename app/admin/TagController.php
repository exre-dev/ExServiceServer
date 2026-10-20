<?php

namespace app\admin;

use OpenApi\Attributes as OA;

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
  #[OA\Get(
    path: '/api/admin/tags',
    operationId: 'adminTagIndex',
    summary: '用户标签列表',
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
                    ref: '#/components/schemas/AdminTagListItem',
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
  public function index(Request $request): Response
  {
    $list = TagModel::withCount('users as user_count')->orderBy('id')->get();
    return success(['list' => $list]);
  }

  /**
   * 创建标签
   */
  #[OA\Post(
    path: '/api/admin/tags',
    operationId: 'adminTagCreate',
    summary: '创建用户标签',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。',
    tags: ['平台管理端'],
    security: [['sessionAuth' => []]],
    x: ['audience' => 'internal'],
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(
            property: 'name',
            type: 'string',
            minLength: 1,
            maxLength: 32,
          ),
          new OA\Property(property: 'note', type: 'string'),
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
  public function create(Request $request): Response
  {
    $name = trim((string) $request->post('name', ''));
    if ($name === '' || mb_strlen($name) > 32) {
      throw new ApiException('标签名为 1-32 个字符');
    }
    if (TagModel::where('name', $name)->exists()) {
      throw new ApiException('标签已存在');
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
    return success(['id' => $tag->id], '标签已创建');
  }

  /**
   * 删除标签（文档可见性引用会同步失效）
   */
  #[OA\Delete(
    path: '/api/admin/tags/{id}',
    operationId: 'adminTagDelete',
    summary: '删除用户标签',
    description: '需要有效登录会话及平台角色 superadmin 或 ops。平台角色不足按当前实现返回400；登录态失效401，封禁或全局限流403。删除标签并使其文档可见性引用失效。不存在的标签也返回成功。',
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
    TagModel::where('id', $id)->delete();
    AuditService::log((int) $request->uid, 'platform', 'tag.delete', [
      'target_type' => 'tag',
      'target_id' => $id,
    ]);
    return success([], '标签已删除');
  }

  /**
   * 设置用户标签（全量替换）
   */
  #[OA\Post(
    path: '/api/admin/users/{id}/tags',
    operationId: 'adminTagSetUserTags',
    summary: '替换用户标签',
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
      required: false,
      content: new OA\JsonContent(
        type: 'object',
        properties: [
          new OA\Property(
            property: 'tag_ids',
            type: 'array',
            items: new OA\Items(type: 'integer'),
            default: [],
            description: '全量替换；省略或非数组清空，无效 ID 被忽略',
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
              ref: '#/components/schemas/AdminTagIds',
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
  public function set_user_tags(Request $request, int $id): Response
  {
    $user = UserModel::find($id);
    if (!$user) {
      throw new ApiException('用户不存在');
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
    return success(['tag_ids' => $tagIds], '标签已保存');
  }
}
