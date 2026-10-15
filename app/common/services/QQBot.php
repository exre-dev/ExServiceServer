<?php

namespace app\common\services;

use support\Request;
use support\Redis;

/***
 * QQBot WebHook接口
 * @package Response
 */
class QQBot
{

  /** 获取 Token URL */
  public static $app_access_token_URL = "https://bots.qq.com/app/getAppAccessToken";
  /** 推送消息URL */
  public static $openapi_url = "https://api.sgroup.qq.com";
  public static $group_message_path = "/v2/groups/{group_openid}/messages";
  public static $c2c_message_path = "/v2/users/{openid}/messages";

  static $m_seq = 1;

  /**
   * 不需要登录验证的方法
   * @var string[]
   */
  protected $noNeedLogin = ['hook'];

  public function hook(Request $request)
  {
    if (getenv("BOT_ENABLE") == "false") {
      return json(["code" => 1, "msg" => "Not Enable"]);
    }

    $data = $request->post();
    $header = $request->header();

    switch ($data["op"]) {
      // 校验操作
      case "13":
        $plain_token = $data["d"]["plain_token"];
        $event_ts = $data["d"]["event_ts"];
        $private_key = sodium_crypto_sign_seed_keypair(getenv('BOT_SECRET'));
        $msg = $event_ts . $plain_token;
        $c_signature = sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($private_key));
        return json(["plain_token" => $plain_token, "signature" => bin2hex($c_signature)]);
      case "0":
        $this->message_event($data);
        break;
      default:
        print_r("test");
    }
  }

  private function message_event($data)
  {
    $type = $data["t"];
    /** 用户id */
    $uid = $data["d"]["author"]["union_openid"];
    /** 消息id */
    $mid = $data["d"]["id"];
    /** 接收的消息 */
    $msg = $data["d"]["content"];

    switch ($type) {
      // 单聊事件
      case "C2C_MESSAGE_CREATE":
        $req = self::postQQ(self::getC2CMessageUrl($uid), [
          "content" => "你好,当前为测试状态. " . $msg,
          "msg_type" => 0,
          "msg_id" => $mid,
        ]);
        break;
      // 群聊@事件
      case "GROUP_AT_MESSAGE_CREATE":
        /** 群id */
        $gid = $data["d"]["group_openid"];
        if (!empty($msg) && substr($msg, 1, 1) == "/") {
          [$cmd, $value] = $this->arrMessage(...explode(" ", $msg));
          switch ($cmd) {
            case "/白名单":
              if ($value === "TXXXX") {
                self::sendGroupMessage($gid, $mid, "示例Code通过");
              } else {
                self::ApprovedWhite($gid, $mid, $value);
              }
              return;
            case "/服务器状态":
              self::sendGroupMessage($gid, $mid, "服务器在线");
              return;
            case "/在线列表":
              self::sendGroupMessage($gid, $mid, "在线人数:5");
              return;
          }

        }
        self::sendGroupMessage($gid, $mid, "未识别的命令");
        return;
    }
  }

  /** 根据白名单code 通过白名单 */
  static function ApprovedWhite($gid, $mid, $code)
  {
    // 获取服务器待通过白名单（false 表示接口离线或状态异常）
    $data = TxAdmin::GetWhiteList();
    if ($data === false) {
      self::sendGroupMessage($gid, $mid, "接口离线,请等待服务器上线后重新发送");
      return;
    }

    // 将数据格式化
    $whitelist = $data["requests"] ?? [];
    foreach ($whitelist as $v) {
      if ($code === $v["id"]) {
        TxAdmin::PassWhiteListwithReqID($v['id']);
        if (self::getGroupType($gid) == "acad") {
          self::sendGroupMessage($gid, $mid, "$code 白名单通过成功,有效期14天");
        } else {
          self::sendGroupMessage($gid, $mid, "$code 白名单通过成功");
        }
        return;
      }
      // self::sendGroupMessage($gid, $mid, "通过失败,未在官网注册账号,或QQ不匹配");
      // return;
    }
    self::sendGroupMessage($gid, $mid, "绑定失败,未找到对应白名单");
  }

  private function arrMessage($code, ...$arr)
  {
    if (count($arr) > 1) {
      return [$arr[0], $arr[1]];
    } else {
      return [$arr[0], ""];
    }

  }

  /** 获取 QQBot 访问 token（Redis 缓存,无则刷新） */
  static function getToken()
  {
    $token = Redis::get("qqbot_token");

    if (!$token) {
      $token = self::GetAppAccessToken();
    }
    return $token;
  }

  /** 带 token 调用 QQ 开放平台接口（JSON POST），失败返回 null */
  static function postQQ(string $url, array $data): ?array
  {
    return HttpClient::make()
      ->post($url)
      ->json($data)
      ->header("Authorization", "QQBot " . self::getToken())
      ->timeout(3)
      ->sendQuietly();
  }

  static function GetMessageSeq()
  {
    $msg_seq = Redis::get("qqbot_msg_seq");

    if (!$msg_seq || $msg_seq > 10) {
      $msg_seq = 1;
    }
    $msg_seq = (int) $msg_seq + 1;

    Redis::set("qqbot_msg_seq", $msg_seq);
    return $msg_seq;
  }

  /** 获取 TOKEN 处理 */
  static function GetAppAccessToken()
  {
    $url = "https://bots.qq.com/app/getAppAccessToken";

    $req = HttpClient::make()
      ->post($url)
      ->json([
        "appId" => getenv("BOT_APPID"),
        "clientSecret" => getenv("BOT_SECRET")
      ])
      ->timeout(5)
      ->sendQuietly();

    $data = $req['json'];

    $token = $data["access_token"];
    $token_timeout = $data["expires_in"];
    Redis::set("qqbot_token", $token);
    // 定时删除key值
    Redis::expire("qqbot_token", intval($token_timeout) - 50);
    // 创建一个延时消息,重新获取token
    // RedisQueue::send('refresh_qqbot_token', [], intval($token_timeout) - 50);
    return $token;
  }

  /** 发送至对于群消息 */
  static function sendGroupMessage(string $gid, string $mid, string $message)
  {
    $req = self::postQQ(self::getGroupMessageUrl($gid), [
      "content" => $message,
      "msg_type" => 0,
      "msg_id" => $mid,
      "msg_seq" => self::GetMessageSeq(),
    ]);

    dump($req["body"] ?? null);
  }

  /**
   * 获取群消息推送URL
   * content 文本内容
   * msg_type 0 文本，2 是 markdown，3 ark 消息，4 embed，7 media 富媒体
   * msg_id 激活当前回复的消息ID
   */
  static function getGroupMessageUrl($group)
  {
    return self::$openapi_url . str_replace("{group_openid}", $group, self::$group_message_path);
  }

  /**
   * 获取单聊消息推送URL
   * content 文本内容
   * msg_type 0 文本，2 是 markdown，3 ark 消息，4 embed，7 media 富媒体
   * msg_id 激活当前回复的消息ID
   */
  static function getC2CMessageUrl($group)
  {
    return self::$openapi_url . str_replace("{openid}", $group, self::$c2c_message_path);
  }

  /** 给机器人拥有者推送事件 */
  static function sendOwnerMessage()
  {
    $qq = getenv("Owner_ID");
  }

  /** 给机器人管理群推送事件 */
  static function sendOwnerGroupMessage()
  {
    $qq = getenv("OwnerGroup_ID");
  }

  /**
   * 获取当前消息所属群组
   *
   * @param string $group_id
   * @return "dev"
   */
  static function getGroupType($gd)
  {
    // if (in_array($group_id, QQBotService::$bot_main_group)) {
    //   return "main";
    // }
    // if (in_array($group_id, QQBotService::$bot_acad_group)) {
    //   return "acad";
    // }
    // if (in_array($group_id, QQBotService::$bot_develop_group)) {
    //   return "develop";
    // }
    return "dev";
  }
}
