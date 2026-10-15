<?php

namespace app\common\services;

use support\Request;
use GatewayWorker\Lib\Gateway;

/**
 * GateWay 操作工具
 * 用于Http访问触发Websocket发送操作
 */
class HttpToGateway
{

  /**
   * 通过Fivem Ident 获取当前连接的数据
   */
  public function getClientDataByIdent(Request $request)
  {
    // Gateway::
  }

  /**
   * 刷新Client在线列表
   */
  public function refreshClient(Request $request)
  {
    $get = $request->get("mode", false);

    // 本地数据
    $local = [
      "0fd982ac0239aa64376213262687c83b67f89824" => [
        "client_id" => "",
        "name" => "Xiamolc"
      ],
      "4f19ca8852ecf283608ee1da9d67e3f9e85f0d11" => [
        "client_id" => "",
        "name" => "Raven James"
      ]
    ];

    $globalList = Gateway::getAllClientIdList();
    $localList = ["type" => "refreshClient", "data" => []];
    foreach ($globalList as $key => $value) {
      $data = Gateway::getSession($key);
      $localList["data"][$data["ident"]] = $data;
    }

    // Gateway::getClientIdByUid("0fd982ac0239aa64376213262687c83b67f89824");
    // Gateway::getSession();
    if ($get == "1") {
      Gateway::sendToAll(json_encode($localList));
    }
    return json_encode($localList);
  }

  /**
   * 强制刷新Client页面
   */
  public function refreshPage(Request $request)
  {
    $js = [
      "type" => "refreshPage",
      "message" => "页面强制更新",
    ];
    $ret = json_encode($js);
    Gateway::sendToAll($ret);
    return $ret;
  }
}
