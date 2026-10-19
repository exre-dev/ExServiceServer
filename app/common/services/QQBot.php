<?php

namespace app\common\services;

use app\common\services\TxAdmin;
use app\common\services\HttpClient;

use support\Request;
use support\Log;
use support\Redis;

/***
 * QQBot WebHook接口
 * @package Response
 */
class QQBot
{

  /** 获取 Token URL */
  public static $app_access_token_URL = "https://api.bot.qq.com/app/getAppAccessToken";
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

  /**
   * 机器人消息主消息入口
   * @param Request $request
   */
  public function hook(Request $request)
  {

    $data = $request->post();
    $header = $request->header();

    // print_r($data);

    switch ($data["op"]) {
      // 校验操作
      case "13":
        $rawBody = $request->rawBody();
        Log::info('QQBot RawBody:', [$rawBody]); // 先记录原始数据，确认非空

        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
          Log::error('QQBot JSON Decode Failed: ' . json_last_error_msg());
          return response('Invalid JSON', 400);
        }

        // 2. 从解析后的数组中取值
        $plain_token = $data['d']['plain_token'] ?? null;
        $event_ts = $data['d']['event_ts'] ?? null;

        if (is_null($plain_token) || is_null($event_ts)) {
          Log::error('QQBot Missing Keys:', ['data' => $data]);
          return response('Missing keys', 400);
        }

        // 3. 生成 seed 并签名 (之前的修正逻辑)
        $bot_secret = getenv('BOT_SECRET');
        $seed = $bot_secret;
        while (strlen($seed) < 32) {
          $seed .= $seed;
        }
        $seed = substr($seed, 0, 32);

        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $msg = $event_ts . $plain_token;

        // 4. 在这里用 Log 记录 $msg，而不是 print_r
        Log::info('QQBot Msg to Sign:', ['msg' => $msg]);

        $signature = sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($keypair));

        // 5. 返回正确格式的 JSON
        return json([
          'plain_token' => $plain_token,
          'signature' => bin2hex($signature),
        ]);
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
    $content = $data["d"]["content"];

    switch ($type) {
      case "GROUP_MESSAGE_CREATE":
        /** 群id */
        $gid = $data["d"]["group_id"];


        self::sendGroupMessage($gid, $mid, $content);
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
    $req = null;
    try {
      // 获取服务器待通过白名单
      $req = TxAdmin::GetWhiteList();
      if ($req->status() !== 200) {
        self::sendGroupMessage($gid, $mid, "接口状态错误 " . $req->status());
        return;
      }
    } catch (\Exception $e) {
      self::sendGroupMessage($gid, $mid, "接口离线,请等待服务器上线后重新发送");
      return;
    }


    // 将数据格式化
    $data = $req->array();
    $whitelist = $data["requests"];
    foreach ($whitelist as $v) {
      if ($code === $v["id"]) {
        TxAdmin::PassWhiteListwithReqID($v['id']);
        // if (self::getGroupType($gid) == "acad") {
        // self::sendGroupMessage($gid, $mid, "$code 白名单通过成功,有效期14天");
        // } else {
        self::sendGroupMessage($gid, $mid, "$code 白名单通过成功");
        // }
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

  static function HttpReq()
  {
    $token = Redis::get("qqbot_token");

    if (!$token) {
      $token = self::GetAppAccessToken();
    }

    return HttpClient::make()->timeout(3)->header("Authorization", "QQBot " . $token);
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
    $req = HttpClient::make()
      ->post(self::$app_access_token_URL)
      ->json([
        "appId" => getenv("BOT_APPID"),
        "clientSecret" => getenv("BOT_SECRET")
      ])
      ->timeout(5)
      ->send();

    print_r($req);
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

    $req = self::HttpReq()->post(self::getGroupMessageUrl($gid))->json([
      "content" => $message,
      "msg_type" => 0,
      "msg_id" => $mid,
      "msg_seq" => self::GetMessageSeq(),
    ])->sendQuietly();

    print_r($req['json']);

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
   * @param string $gid
   * @return "dev"
   */
  static function getGroupType($gid)
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
