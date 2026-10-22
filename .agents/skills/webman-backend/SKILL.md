---
name: webman-backend
description: 本项目（webman/workerman 常驻内存 PHP 后端）的代码规范与写法。凡是新增或修改本项目任何 PHP 代码时必须使用——包括写 Controller、Model、Service、中间件、路由、异常处理、Redis/配置读取，以及用户提到接口、API、登录注册、白名单、邮件、QQ 机器人、TxAdmin、Gateway 推送等功能时都应触发，即使用户没有明说"按规范写"。
---

# webman 后端开发

本项目是 webman（基于 workerman 的 PHP 常驻内存框架，PHP >= 8.1）后端，业务为 EXRE 社区（FiveM 游戏服务器）：B2B2C 插件服务平台（账号、空间、成员、绑定码、SSO、文档），另有 TxAdmin、QQ 机器人、Gateway 推送等自营模块。

## 使用方式

- 动手写/改任何 PHP 代码前，先读 `references/conventions.md`（编码规范：目录结构、路由、Controller、Model、Service、中间件、响应格式、异常、Redis、代码风格）；本次会话已读过则不必重读。
- 涉及登录鉴权、管理端、开放 API、OAuth、文档等业务模块时，再读 `references/architecture.md`。
- 需要本地跑起来联调时，读 `references/testing.md`。

## 任务：新增/修改一个 API 接口

最常见的任务，规范已融入步骤：

1. **定位模块**：账号 `app/account/`、平台管理端 `app/admin/`、空间管理端 `app/server/`、开放 API `app/open/`、OAuth `app/oauth/`、文档浏览 `app/docs/`；跨模块共享逻辑放 `app/common/`。新建模块 = `app/<模块>/XxxController.php`，命名空间 `app\<模块>`，控制器不继承任何基类。
2. **写控制器方法**（完整模板见 conventions.md「Controller 规范」）：
   - 签名 `public function xxx(Request $request): Response`（`support\Request` / `support\Response`）。
   - 参数校验惯用式：`if (empty($request->post()) || hasMissingKeys($request->post(), ["email"])) { throw new ApiException("参数不匹配"); }`。
   - 业务错误一律 `throw new ApiException("中文消息")`；成功 `success($data)`，模型先 `->toArray()`。
   - 需要模型时参数注入（`UserModel $model`），不要自己 `new`。
3. **注册路由**：`app/route.php` 里对应模块组（都在顶部 `/api` 大组内），每条路由上方一行中文注释；路由参数名与方法参数名**完全一致**（`{sid}` → `$sid`），否则报 "Missing input parameter"：

   ```php
   //账号安全中心
   Route::group('/account', function () {
     // 发送邮箱验证码（注册用, 60秒冷却）
     Route::post('/send-email-code', [app\account\AccountController::class, 'send_email_code']);
   });
   ```

4. **定鉴权**（详见 architecture.md「登录鉴权」）：
   - 默认需登录（CheckLogin）；免登录方法加进 `$noNeedLogin`，整控制器免登录写 `'*'`。
   - `/api/admin` 组已挂 PlatformAdmin；`/api/server/{sid}` 组已挂 ServerMember，管理动作开头调 `assertManager($request)`（owner/admin）或 `assertOwner`。
   - 机器调用放 `/api/open` 组（BindCodeAuth）；OAuth 访问令牌接口挂 OAuthTokenAuth。
5. **可选限流**：`#[Limit(limit: 3, ttl: 60, message: '当前IP注册次数过多,请稍后再试!')]`（webman/limiter，Redis 驱动）。
6. **自查**：路由注册进了 `/api` 组且注释齐全；没有手写 `json(...)` / `Json(...)`；报错是中文且走 ApiException；序列化用 `toArray()` 不是 `(array)$model`。

## 任务：新增数据表 + 模型

1. `database/schema.sql` 加表：`app_` 前缀表名、每字段中文 COMMENT、`created_time`/`updated_time` 两列、幂等 DROP+CREATE；改完重新执行 schema.sql（测试阶段不写迁移）。
2. 写 `app/common/model/XxxModel.php`：**继承 `BaseModel`**（不要直接继承 `support\Model`，否则时间戳输出 ISO8601）；`protected $table` 显式声明；类 docblock 用 `@property` 逐字段中文注释（项目查字段主要靠它）；`$fillable` 白名单；敏感哈希字段加 `$hidden`。
3. 需要种子数据就加 `database/seed.sql`。
4. 复杂查询写成模型上的助手方法（静态或实例），控制器不写复杂查询；控制器用 `with()/withCount()` 前确认模型已定义对应的关系方法。

## 任务：新增中间件

1. `app/common/middleware/` 下新建，实现 `Webman\MiddlewareInterface::process(Request $request, callable $next): Response`，结尾 `return $next($request);`。
2. 全局的注册进 `config/middleware.php`，注意顺序：AccessControl（CORS）→ Timing → Limit → CheckLogin；局部的挂路由 `->middleware([...])`（写法见 conventions.md 路由规范的 fallback 示例）。
3. 不要在中间件里加跨域头——CORS 已由 AccessControl 统一处理。

## 任务：新增外部服务封装

1. `app/common/services/Xxx.php`，静态方法工具类。
2. HTTP 调用一律用自带 `HttpClient` 流式构建器（三种用法见 conventions.md「Service 规范」），`json()`/`form()`/`body()` 三选一；禁止裸用 Guzzle 或引入新的 HTTP 客户端包。
3. 密钥/凭据放 `.env` 用 `getenv()` 读；要缓存的 token 存 Redis 并设置过期时间。

## 红线（任何任务都适用）

- 密码一律 bcrypt（`password_hash()` / `password_verify()`），禁止 md5。
- 签发 JWT 只走 `SessionService::create()`，不要直接调 `JwtToken::generateToken()`（绕过会话记录，token 将无法撤销）。
- 敏感值（`POSTGRES_*`、`MAIL_*`、`BOT_*`、`TXADMIN_*`、`Owner_ID` 等）放 `.env`，不硬编码。
- QQBot/TxAdmin 是自营私有模块，不暴露给外部空间/开放 API。
- 历史遗留（`Json(["code"=>0/1])` 旧格式、gatewayworker 包缺失等，见 conventions.md「历史遗留」）不要顺手改，除非用户要求。
