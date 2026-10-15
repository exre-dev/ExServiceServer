<?php

namespace app\common\services;

use support\Request;
use support\Redis;

use app\common\model\UserModel;

/**
 * TxAdmin 接口 用于白名单校验
 */
class TxAdmin
{
    /** TxAdmin 接口URL  */
    private static $txadmin_url = "https://server-admin.xiamolc.cn";

    private static $txadmin_version = "8.1.1";

    /** 伪装浏览器 UA */
    private const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36 Edg/114.0.1823.58";

    /** 从 Redis 取会话组装通用请求头 */
    private static function headers(): array
    {
        return [
            "X-Requested-With" => "XMLHttpRequest",
            "X-TxAdmin-CsrfToken" => Redis::get("tx_csrfToken") ?? "",
            "User-Agent" => self::UA,
            "Cookie" => Redis::get("tx_cookie") ?? "",
        ];
    }

    /**
     * 发起底层请求（不带会话过期重试）
     * @return array{status:int, headers:array, body:string, json:mixed}
     */
    private static function doRequest(string $url, string $type = "GET", array $data = []): array
    {
        $req = HttpClient::make()
            ->headers(self::headers())
            ->timeout(5);

        if (strtoupper($type) === "POST") {
            $req->post(self::$txadmin_url . $url)->form($data);
        } else {
            $req->get(self::$txadmin_url . $url);
            if ($data) {
                $req->query($data);
            }
        }

        return $req->sendQuietly() ?? ['status' => 0, 'headers' => [], 'body' => '', 'json' => null];
    }

    /**
     * 带会话的请求：会话过期时自动重新登录并重试一次
     */
    private static function request(string $url, string $type = "GET", array $data = []): array
    {
        $res = self::doRequest($url, $type, $data);
        $json = $res["json"];

        // 响应带 type/logout 说明会话过期
        if (is_array($json) && (!empty($json["type"]) || !empty($json["logout"]))) {
            self::relogin();
            $res = self::doRequest($url, $type, $data);
        }

        return $res;
    }

    /** 重新登录 TxAdmin 并把 Cookie/CsrfToken 缓存到 Redis */
    private static function relogin(): void
    {
        Redis::del("tx_cookie", "tx_csrfToken");

        $res = HttpClient::make()
            ->post(self::$txadmin_url . "/auth/password?uiVersion=" . self::$txadmin_version)
            ->form([
                "username" => getenv("TXADMIN_USER"),
                "password" => getenv("TXADMIN_PASSWORD"),
            ])
            ->header("User-Agent", self::UA)
            ->timeout(5)
            ->sendQuietly();

        if (!$res || $res["status"] !== 200) {
            \support\Log::error("TxAdmin 重新登录失败: " . ($res["body"] ?? "请求失败"));
            return;
        }

        Redis::set("tx_cookie", $res["headers"]["Set-Cookie"][0] ?? "");
        Redis::set("tx_csrfToken", $res["json"]["csrfToken"] ?? "");
    }

    /** 获取待通过的白名单 */
    public static function GetWhiteList()
    {
        $res = self::request("/whitelist/requests?");
        if ($res["status"] == 200) {
            return $res["json"];
        }
        return false;
    }

    /** 通过ReqId 通过白名单 */
    public static function PassWhiteListwithReqID($reqid)
    {
        $res = self::request("/whitelist/requests/approve", "POST", ["reqId" => $reqid]);
        if ($res["status"] == 200) {
            return $res["json"];
        }
        return false;
    }

    /** 设置玩家白名单 ident status|true|false*/
    public static function SetWhiteByIdent(string $ident, bool $status)
    {
        $res = self::request("/player/whitelist?license=$ident", "POST", ["status" => $status]);
        if ($res["status"] == 200) {
            $arr = $res["json"];
            return isset($arr["success"]) ? true : false;
        }
        return false;
    }

    /** 踢出玩家 */
    public static function kickPlayer($ident, $msg)
    {
        $res = self::request("/player/kick?license=$ident", "POST", ["reason" => $msg]);

        if ($res["status"] == 200) {
            $arr = $res["json"];
            if (!empty($arr["success"])) {
                return true;
            }
            if (!empty($arr["error"]) && $arr["error"] == "This player is not connected to the server.") {
                return true;
            }
        }
        return false;
    }

    /** 封禁玩家 */
    public static function banPlayer($ident, $duration, $msg)
    {
        $res = self::request("/player/ban?license=$ident", "POST", ["reason" => $msg, "duration" => $duration]);
        if ($res["status"] == 200) {
            $arr = $res["json"];
            return isset($arr["success"]) ? true : false;
        }
        return false;
    }

    /** 获取带通过的白名单 */
    public function GetWhiteListRequests(Request $request)
    {
        $Req = $this->GetWhiteList();
        return Success($Req);
    }

    /** 获取待进入游戏的白名单 */
    public function GetWhiteListApproved()
    {
        $Req = self::request("/whitelist/approvals");
        return $Req["body"];
    }

    /** 根据reqID通过白名单 */
    public function PassWhiteListByReqID(Request $request)
    {
        $id = $request->get("id");
        $Req = self::request("/whitelist/requests/approve", "POST", ["reqId" => $id]);
        return $Req["body"];
    }

    /** 根据License通过白名单(全id)(只能用作待进入玩家) */
    public function PassWhiteListByLicense(Request $request)
    {
        $license = $request->get("license");
        $Req = self::request("/whitelist/approvals/remove", "POST", ["identifier" => $license]);
        return $Req["body"];
    }

    /** 根据ident通过白名单(只能用作已加入游戏玩家) */
    public function PassOrRemoveWhiteListByIdent(Request $request)
    {
        if ($request->method() == "POST") {
            $ident = $request->post("license");
            $status = $request->post("isWhitelisted");
            $Req = self::request("/player/whitelist?license=$ident", "POST", ["status" => $status]);
            $arr = $Req["json"];
            if (!empty($arr["success"])) {
                return Json(["msg" => "执行成功", "code" => 0]);
            }
        }

        return Json(["msg" => "方法错误", "code" => 1]);
    }

    /** 获取所有玩家列表 */
    public function GetAllPlayerInfo()
    {
        $Req = $this->GetAllPlayerAction();

        // 检查是否通过reqid 激活账号
        foreach ($Req as $key => $value) {
            $req = UserModel::hasBindAndRegister($value["license"]);
            $Req[$key]["isRegister"] = $req[0];
            $Req[$key]["isBinding"] = $req[1];
        }

        return Json(["msg" => "ok", "code" => 0, "data" => $Req]);
    }

    /** 私有方法 不对外暴露 */
    private function GetAllPlayerAction($Param = null, $License = null)
    {
        $list = [];
        if ($Param && $License) {
            $arr1 = self::request("/player/search?sortingKey=tsJoined&sortingDesc=true&offsetParam=$Param&offsetLicense=$License")["json"];
        } else {
            $arr1 = self::request("/player/search?sortingKey=tsJoined&sortingDesc=true")["json"];
        }
        if (!is_array($arr1)) {
            return $list;
        }
        if (!$arr1["hasReachedEnd"]) {
            $arr2 = $this->GetAllPlayerAction($arr1["players"][0]["tsJoined"], $arr1["players"][0]["license"]);
            unset($arr2[0]);
            $list = array_merge($arr1["players"], $arr2);
        } else {
            $list = $arr1["players"];
        }
        return $list;
    }

    /**
     * 数据返回调用使用
     */
    private function BodyToJson($body)
    {
        $req = json_decode($body, true);
        if (!empty($req["type"])) {
            return $this->TxRequest("error", "接口错误，请重新刷新，若长时间错误请联系管理员");
        }

        if (!empty($req["logout"])) {
            return $this->TxRequest("error", "接口离线，请重新刷新，若长时间错误请联系管理员");
        }

        return $this->TxRequest("success", "请求成功", $req);
    }

    private function TxRequest($type, $message, $data = [])
    {
        switch ($type) {
            case "error":
                return Json(["msg" => $message, "code" => 1]);
            case "success":
                return Json(["msg" => $message, "code" => 0, "data" => $data]);
        }
    }
}
