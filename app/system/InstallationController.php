<?php

namespace app\system;

use app\common\exception\ApiException;
use app\common\services\InstallationService;
use support\Request;
use support\Response;

class InstallationController
{
  protected $noNeedLogin = '*';

  /** 查询安装状态及运行环境。 */
  public function status(Request $request, InstallationService $installer): Response
  {
    return success($installer::status())->withHeader('Cache-Control', 'no-store');
  }

  /** 首次部署导入数据库并创建超级管理员。 */
  public function install(Request $request, InstallationService $installer): Response
  {
    // 独立请求头配合同源检查，阻止第三方页面代替部署者提交安装表单。
    $origin = $request->header('origin');
    $originHost = $origin ? (string) parse_url($origin, PHP_URL_HOST) : '';
    $originPort = $origin ? parse_url($origin, PHP_URL_PORT) : null;
    $originAuthority = strtolower($originHost . ($originPort ? ':' . $originPort : ''));
    if ($request->header('x-setup-request') !== '1' || ($origin && $originAuthority !== strtolower($request->host()))) {
      throw new ApiException('请从当前部署的初始化页面提交', 403);
    }
    $installer::install($request->post());
    return success(['installed' => true]);
  }
}
