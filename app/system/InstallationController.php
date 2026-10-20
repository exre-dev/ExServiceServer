<?php

namespace app\system;

use OpenApi\Attributes as OA;

use app\common\exception\ApiException;
use app\common\services\InstallationService;
use support\Request;
use support\Response;

class InstallationController
{
  protected $noNeedLogin = '*';

  /** 查询安装状态及运行环境。 */
  #[OA\Get(
    path: '/api/installation/status',
    operationId: 'systemInstallationStatus',
    summary: '初始化状态和环境检查',
    description: '免登录，跳过首次安装保护。已安装仅返回 installed=true；否则检查 PHP/扩展、目录及配置可写和数据库脚本可读。检测已有 superadmin 时可补写 install.lock；不返回配置凭据。',
    tags: ['系统'],
    security: [],
    responses: [
      new OA\Response(
        response: 200,
        description: '成功',
        headers: [
          new OA\Header(
            header: 'Cache-Control',
            schema: new OA\Schema(type: 'string', enum: ['no-store']),
          ),
        ],
        content: new OA\JsonContent(
          type: 'object',
          required: ['msg', 'data'],
          properties: [
            new OA\Property(property: 'msg', type: 'string'),
            new OA\Property(
              property: 'data',
              ref: '#/components/schemas/SystemInstallationStatusData',
            ),
          ],
        ),
      ),
      new OA\Response(
        response: 403,
        description: 'IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function status(
    Request $request,
    InstallationService $installer,
  ): Response {
    return success($installer::status())->withHeader(
      'Cache-Control',
      'no-store',
    );
  }

  /** 首次部署导入数据库并创建超级管理员。 */
  #[OA\Post(
    path: '/api/installation/install',
    operationId: 'systemInstallationInstall',
    summary: '首次部署初始化',
    description: '免登录。必须携带 X-Setup-Request: 1；如提供 Origin，其主机与端口必须等于请求 Host（否则 403）。已安装、本机安装锁竞争、数据库 advisory lock 竞争、已有 app_ 表但未 force 均返回 409。环境不就绪、输入不合法、Redis 读写失败、数据库连接或建表失败返回 400。force=true 必须同时给出 force_confirmation=重置数据库，在事务内重建 public 下 app_ 业务表；外部依赖保护仍生效。创建首个 superadmin，写入数据库/Redis 配置及随机 JWT 密钥，保留其他环境项，不回传密码。',
    tags: ['系统'],
    security: [],
    parameters: [
      new OA\Parameter(
        name: 'X-Setup-Request',
        in: 'header',
        required: true,
        description: '固定值 1；初始化页面专用请求头',
        schema: new OA\Schema(type: 'string', enum: ['1']),
      ),
      new OA\Parameter(
        name: 'Origin',
        in: 'header',
        required: false,
        description: '可选；存在时主机和端口必须与 Host 相同',
        schema: new OA\Schema(type: 'string'),
      ),
    ],
    requestBody: new OA\RequestBody(
      required: true,
      content: new OA\JsonContent(
        ref: '#/components/schemas/SystemInstallInput',
      ),
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
              ref: '#/components/schemas/SystemInstallData',
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
        description: '初始化请求头缺失或 Origin 主机/端口不匹配；也可能触发 IP 错误请求限流',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
      new OA\Response(
        response: 409,
        description: '已经安装、安装锁竞争，或数据库存在业务表但未选择强制初始化',
        content: new OA\JsonContent(
          ref: '#/components/schemas/BusinessErrorBody',
        ),
      ),
    ],
    x: ['audience' => 'internal'],
  )]
  public function install(
    Request $request,
    InstallationService $installer,
  ): Response {
    // 独立请求头配合同源检查，阻止第三方页面代替部署者提交安装表单。
    $origin = $request->header('origin');
    $originHost = $origin ? (string) parse_url($origin, PHP_URL_HOST) : '';
    $originPort = $origin ? parse_url($origin, PHP_URL_PORT) : null;
    $originAuthority = strtolower(
      $originHost . ($originPort ? ':' . $originPort : ''),
    );
    if (
      $request->header('x-setup-request') !== '1' ||
      ($origin && $originAuthority !== strtolower($request->host()))
    ) {
      throw new ApiException('请从当前部署的初始化页面提交', 403);
    }
    $installer::install($request->post());
    return success(['installed' => true]);
  }
}
