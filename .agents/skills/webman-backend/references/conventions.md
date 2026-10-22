# webman 后端编码规范

声明式规范：写什么工件、遵循什么约束。任务流程见 SKILL.md。

## 框架本质（决定所有写法）

- **常驻内存**：代码只加载一次，进程不死。不要在全局/静态属性中无限累积数据（内存泄漏）；不要用 PHP-FPM 的"每请求重载"思维。
- `config/app.php` 中 `controller_reuse => false`：每个请求新建 Controller 实例。
- **默认路由已关闭**（`config/route.php` 里 `Route::disableDefaultRoute()`）：**所有 URL 必须在 `app/route.php` 显式注册**，没注册的路径落到 `Route::fallback`（404）。
- 本机为 Windows，启动用 `php windows.php`（或 `windows.bat`）；Linux 生产环境用 `php start.php start -d`。

## 目录结构（单应用 + common 共享层）

```
app/
├── route.php              # 所有显式路由（接口入口，必改文件）
├── functions.php          # 全局助手函数
├── account/               # 账号安全中心（登录/注册/会话/授权/改密）
├── admin/                 # 平台管理端控制器
├── server/                # 空间管理端控制器
├── open/                  # 开放 API（插件/机器调用）
├── oauth/                 # SSO/OAuth2（pages/ 下为确认页 HTML）
├── docs/                  # 文档浏览（免登录）
└── common/                # 跨模块共享层
    ├── exception/         # ApiException、ApiHandler
    ├── middleware/        # AccessControl、Timing、Limit、CheckLogin、PlatformAdmin、ServerMember、BindCodeAuth、OAuthTokenAuth
    ├── model/             # XxxModel（继承 BaseModel）
    ├── process/           # Http、Monitor
    └── services/          # Email、HttpClient、QQBot、TxAdmin、SessionService、AuditService、OAuthService、PermService、HttpToGateway
```

| 要写什么 | 位置 | 命名空间 |
|---|---|---|
| 控制器 | `app/<模块>/XxxController.php`（后缀必须，来自 config） | `app\<模块>` |
| 数据模型 | `app/common/model/XxxModel.php`（继承 BaseModel） | `app\common\model` |
| 中间件 | `app/common/middleware/` | `app\common\middleware` |
| 工具/外部服务封装 | `app/common/services/` | `app\common\services` |
| 异常 | `app/common/exception/` | `app\common\exception` |
| 全局函数 | `app/functions.php` | 全局 |
| 路由 | `app/route.php` | — |

## 路由规范

- **所有后端接口统一挂在 `/api` 前缀下**（`app/route.php` 顶部 `/api` 组包住 account/admin/server/open/oauth/docs 等组）；只有 OAuth 浏览器页面（`/oauth/authorize`、`/oauth/device`）在 `/api` 之外。
- 按模块分组、每条路由上方一行中文注释。
- options 预检置顶保留；404 fallback 置底保留。

```php
use Webman\Route;

/** options 请求（CORS 预检，置顶保留） */
Route::options('[{path:.+}]', function () {
  return response('');
});

//所有后端接口统一挂在 /api 前缀下
Route::group('/api', function () {

  //账号安全中心
  Route::group('/account', function () {
    // 注册
    Route::post('/register', [app\account\AccountController::class, 'register']);
    // 发送邮箱验证码（注册用, 60秒冷却）
    Route::post('/send-email-code', [app\account\AccountController::class, 'send_email_code']);
  });

  // ...其余模块组（admin/server/open/oauth/docs）同样注册在 /api 内
});

// 404（置底保留；miss 模式的 Limit 会把 404 也计入 IP 错误计数）
Route::fallback(function () {
  return fail('API NOT FOUND', 404);
})->middleware([new app\common\middleware\Limit('miss'), app\common\middleware\Timing::class, app\common\middleware\AccessControl::class]);
```

## Controller 规范

不继承任何基类。新控制器照抄这个模板（参考 `app/account/AccountController.php`）：

```php
<?php

namespace app\account;

use app\common\exception\ApiException;
use support\limiter\annotation\Limit;
use support\Request;
use support\Response;

class XxxController
{
  /**
   * 不需要登录验证的方法
   * @var string[]
   */
  protected $noNeedLogin = ['some_public_action'];

  /**
   * 方法注释（中文）
   */
  public function some_action(Request $request): Response
  {
    if (empty($request->post()) || hasMissingKeys($request->post(), ["email"])) {
      throw new ApiException("参数不匹配");
    }
    $uid = $request->uid; // CheckLogin 中间件注入的当前用户 id
    return success(["id" => $uid]);
  }
}
```

规则：
- 动作签名 `public function xxx(Request $request): Response`，使用 `support\Request` / `support\Response`。
- 需要模型时直接参数注入：`public function info(Request $request, UserModel $model)`，不要自己 `new`。
- **方法参数名必须与路由参数名完全一致**（`{sid}` → `$sid`、`{client_id}` → `$client_id`），否则报 "Missing input parameter"。
- 业务错误**一律** `throw new ApiException("中文错误消息")`，不要手动构造错误响应。
- 需要限流的动作加注解：`#[Limit(limit: 3, ttl: 60, message: '当前IP注册次数过多,请稍后再试!')]`（webman/limiter，Redis 驱动）。
- 免登录方法写进 `$noNeedLogin` 数组；全部免登录写 `protected $noNeedLogin = '*';`。
- 注释、docblock、用户可见的报错消息全部用**中文**。

## 统一响应格式

用 `app/functions.php` 里的全局助手，**不要**手写 `json(['code' => ...])`（`Json(["code"=>0,...])` 是历史遗留，新代码禁止）：

```php
success($data);            // 200: {"msg":"ok","data":{...}}   data 可省略
success($info->toArray()); // 模型先 ->toArray()
success(["token" => $t], "注册成功"); // 第二参可传自定义 msg
fail("错误消息");           // 400: {"msg":"错误消息"}（第二参可改 HTTP 状态码）
```

注意：成功响应没有 `code` 字段，错误靠 HTTP 状态码 + `msg` 区分。

## 错误码契约

- `ApiException` 第二参数即 HTTP 状态码：`new ApiException("消息", 401)`；`render()` 会透传。
- **401 = 登录态失效**（token 缺失/无效/过期/会话撤销/用户不存在）；403 = 身份有效但被拒（封禁、无权限）；400 = 普通业务错误（默认）。
- `CheckLogin` 里 JWT 校验必须 try/catch 包住 Tinywan JwtToken::verify() 转成 401——框架 JWT 异常若放任抛出会走 ApiHandler 变 500+message 字段，调用方认不出。

## Model 规范

继承 `app/common/model/BaseModel`（它覆写 `serializeDate` 保证时间戳输出 `Y-m-d H:i:s`；**不要**直接继承 `support\Model`，否则 Eloquent 输出 ISO8601）：

```php
<?php

namespace app\common\model;

/**
 * @property integer $id 主键
 * @property string $email 邮箱
 * @property string $created_time 创建时间
 */
class FooModel extends BaseModel
{
    protected $table = 'app_foos';

    const CREATED_AT = "created_time";
    const UPDATED_AT = "updated_time";

    /** 可批量赋值的属性 */
    protected $fillable = [
        "nickname",
    ];
}
```

规则：
- 类 docblock 用 `@property` 逐字段注释（类型 + 中文说明），这是项目查字段的主要方式。
- 表名用 `protected $table` 显式声明（表有 `app_` 前缀风格）。
- **不写 `$connection` 属性**，跟随 `config/database.php` 的 `default` 连接（pgsql）。
- 时间戳列是 `created_time`/`updated_time`（上面两个常量）。
- 敏感哈希字段加 `$hidden`（如 OAuthClientModel 隐藏 `client_secret_hash`）。
- 控制器用 `with()/withCount()` 前确认模型已定义对应的关系方法。
- 序列化用 `$row->toArray()`；`(array)$model` 转换拿不到 Eloquent 属性。
- 单条查询助手直接写在模型上（静态或实例方法），控制器不写复杂查询。

## 异常体系

- 业务异常：`throw new ApiException("消息")`（`app/common/exception/ApiException.php`，继承 BusinessException，`render()` 自动返回 `fail()`，HTTP 400）。
- 兜底：`app/common/exception/ApiHandler.php`（`config/exception.php` 注册），非 ApiException 一律 500 `{"message": ...}`。
- 新增异常场景不需要建新异常类，直接 `ApiException` + 中文消息。

## 中间件规范

全局注册在 `config/middleware.php`，顺序：`AccessControl`（CORS）→ `Timing`（计时/错误打印）→ `Limit`（IP 错误限流）→ `CheckLogin`（JWT 鉴权）。

- 新中间件放 `app/common/middleware/`，实现 `Webman\MiddlewareInterface::process(Request $request, callable $next): Response`，结尾 `return $next($request);`。
- CORS 已由 AccessControl 处理，不要在别处重复加跨域头。
- `Limit` 中间件（IP 错误限流）：Redis 键 `rip_{ip}` 计数，请求出错（异常/非200/miss）时 +1，30 秒窗口内 ≥5 次直接 `fail("请求过于频繁", 403)`。构造参数标记模块名（`new Limit('miss')` 用于 fallback 场景）。路由可挂局部中间件（见路由规范的 fallback 示例）。
- `Limit` 在 debug 开启时对 127.0.0.1/::1 自动豁免（本地联调不受影响；生产关 debug 即恢复限流）。

## Service 规范（app/common/services/）

静态方法工具类。外部 HTTP 调用**一律**用自带的 `HttpClient` 流式构建器（不要裸用 Guzzle）：

```php
// 抛异常版（网络失败/超时重试耗尽时抛 RuntimeException）
$res = HttpClient::make()
    ->post("https://api.example.com/users")
    ->json(["page" => 1])            // JSON body（QQ 开放平台等）
    ->header("Authorization", "Bearer xxx")
    ->timeout(5)
    ->retry(2)
    ->send();
// 返回 ['status'=>int, 'headers'=>array, 'body'=>string, 'json'=>array|null]

// 表单版（application/x-www-form-urlencoded，TxAdmin 等）
$res = HttpClient::make()->post($url)->form(["reqId" => $id])->send();

// 安静版：失败返回 null 并记日志
$res = HttpClient::make()->get($url)->query(["id" => 1])->sendQuietly();
```

外部 HTTP 请求（含 QQ 机器人、TxAdmin）**统一走 `HttpClient`**，不要引入新的 HTTP 客户端包。`json()`/`form()`/`body()` 三选一，后调用的生效。

现成服务：`Email::send($to, $subject, $body, $isHtml = true): bool`（SMTP 配置在 `config/email.php`）；`TxAdmin`（白名单/踢人/封禁）；`QQBot`（QQ 开放平台 webhook）；`HttpToGateway`（WebSocket 广播）；`SessionService`（会话签发/撤销）；`AuditService::log()`（审计）；`OAuthService`、`PermService`。

## Redis / 配置 / 环境变量

```php
use support\Redis;
Redis::set($key, $value, 60);   // 第三个参数是过期秒数
Redis::set($key, $v, "EX", 30);  // 也可用 Redis 原生选项写法（见 Limit 中间件）
Redis::get($key); Redis::expire($key, $ttl); Redis::del($k1, $k2);

config('email');                                  // config/<文件名>.php
config('plugin.tinywan.jwt.app.jwt.access_exp');  // 插件配置
getenv('BOT_APPID');                              // .env 变量
```

- 敏感值（`DB_*`、`MAIL_*`、`BOT_*`、`POSTGRES_*`、`TXADMIN_USER/TXADMIN_PASSWORD`、`Owner_ID` 等）放 `.env`，代码用 `getenv()` 读，不要硬编码。
- 外部 token 之类要缓存的值存 Redis 并设置过期时间（参考 `QQBot::GetAppAccessToken`）。

## 代码风格

- 缩进跟随所在目录现状：`app/<模块>/`、`app/common/middleware/` 多为 2 空格，`app/common/model/`、`app/common/services/` 多为 4 空格；同文件内保持一致。
- 无 `strict_types`，新代码不加。
- 调试输出（`print_r`/`dump`/`echo`）是历史习惯，新代码请用 `\support\Log::error/warning(...)`。
- 全局函数放 `app/functions.php`（composer 自动加载，勿在别处 require）。

## 历史遗留（不要顺手改，除非用户要求）

- 部分老服务（TxAdmin 的 Json 响应式接口）仍返回 `Json(["code"=>0/1])` 旧格式——它们未挂路由，属自营遗留。
- **gatewayworker 包缺失**：`HttpToGateway` 用 `GatewayWorker\Lib\Gateway`，该包不在 vendor 里，调用时会 class not found；未决定补装或改写前别在新代码里引入。
- QQBot/TxAdmin 为**自营私有**模块（蓝图约定不暴露给外部空间）。
