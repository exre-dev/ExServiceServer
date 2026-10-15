# 业务架构与模块

鉴权体系与各业务模块的结构约定。涉及这些模块的新接口/改动时读本文件。

## 登录鉴权（会话化 JWT）

- 密码一律 **bcrypt**（`password_hash()` / `password_verify()`），禁止 md5。
- 登录/注册/登出在 `app/account/AccountController`（`/api/account/*`），登录时经 `SessionService::create()` 建会话（`app_auth_sessions` 表）并签发带 `sid` claim 的 JWT。
- `CheckLogin` 中间件校验链：JWT 签名/有效期 → Redis `sess:{sid}` 必须 active → 用户实时状态（封禁即拦）。**撤销 token = `SessionService::revoke($sid)` 删 Redis，下一个请求立即失效**，无需等 JWT 过期。
- 控制器里读 `$request->uid`（用户 id）、`$request->sid`（会话 id）；空间路由组里另有 `$request->server_role`（ServerMember 中间件注入）。
- 签发 token 统一走 `SessionService::create()`，**不要**直接调 `JwtToken::generateToken()`（会绕过会话记录，token 将无法撤销）。
- 角色中间件：`PlatformAdmin`（`/api/admin` 用，按 `app_users.platform_role` 放行 superadmin/ops）、`ServerMember`（`/api/server/{sid}` 用，校验成员关系并注入空间上下文）；两者都必须排在 CheckLogin 之后。

## 平台管理端与空间管理端

- **平台管理端** `/api/admin/*`：控制器在 `app/admin/`，组上挂 `PlatformAdmin`；列表接口统一 `success(['list' => ..., 'total' => ...])`，分页/搜索参数 `page/page_size/keyword`。
- **空间管理端** `/api/server/{sid}/*`：控制器在 `app/server/`，组上挂 `ServerMember`；管理接口开头必须调 `ServerMember::assertManager($request)`（owner/admin）或 `assertOwner`；`/api/server/mine` 在 {sid} 参数组**之外**（路由注册须在前）。
- 绑定码格式 `EX-XXXXX-XXXXX-XXXXX-XXXXX`（去 0/O/1/I）；游戏绑定走 Redis 一次性 ticket `bind:tkt:{6位}`。
- 敏感操作全部 `AuditService::log()`；改权限/绑定码/状态后调 `PermService::clearCache($serverId, $pluginSlug)` 让 /open 心跳立即生效。

## 开放 API 与插件 SDK

- **`/api/open/*`**（机器调用，`BindCodeAuth` 中间件）：绑定码取自 `X-Bind-Code` 头或 body `bind_code`；控制器在 `app/open/`，每个都有 `protected $noNeedLogin = '*'`。现有接口：`license/validate`（启动校验+心跳，首次校验锁定 server_key_hash）、`ident/bind-ticket`、`perm/batch`、`perm/rev`（权限版本号快速同步）、`telemetry`（落 `runtime/logs/open-telemetry.log`）。
- 心跳缓存键 `lic:{server_id}:{plugin_slug}`（300s）、权限缓存 `perm:{sid}:{slug}`（60s）；吊销/重置/改权限后由管理端调 `PermService::clearCache` 即时失效。
- **插件 SDK** 在 `sdk/fivem-excore/`（fxmanifest + excore.lua + README，纯 Lua 5.4）：convar 配置 `exre_bind_code/exre_api_base/exre_grace_hours/exre_heartbeat_ms`，业务判断唯一入口 `exports.excore:IsAllowed(src, featureKey)`，事件 `excore:OnLicenseValid/OnLicenseRevoked/...`。改 open 接口契约时必须同步检查 SDK。

## SSO / OAuth2

- **两流**：授权码+PKCE（第三方平台，`/oauth/authorize` 确认页 → `/api/oauth/authorize-info|authorize/confirm` → `/api/oauth/token`）；设备码流（插件，`/api/oauth/device/init|token` 轮询 + `/api/oauth/device/verify` 用户确认 + `/oauth/device` 验证页）。授权码/设备码只存 Redis（`oauth:code:*` 300s、`device:*` 600s，一次性）。
- **页面**：`app/oauth/pages/*.html` 自包含 HTML（fetch 调 /api）；页面路由在 `/api` 组之外。
- **访问令牌**：复用会话化 JWT（`SessionService::create` 传 `login_type=oauth` + `client_id` + `scope`）；`OAuthTokenAuth` 中间件校验链 = JWT → 会话 active → client active → grant active，挂 `oauth_user_id/oauth_client/oauth_scopes`；`/api/open/userinfo`（scope 硬过滤）与 `/api/open/ident/bind-self`（插件自绑定即入空间）。
- **撤销链**：用户撤销授权 = `OAuthService::revokeGrant` + `SessionService::revokeAllForUserClient`（该应用名下的 token 立即 401）；账号安全中心接口在 `/api/account/{sessions,grants,password}`。

## 文档服务

- 管理端：平台文档 `/api/admin/docs`（仅超管）；空间文档 `/api/server/{sid}/docs`（可见性 public/members/roles，roles 时 owner/admin 恒可见）；各配 `doc-categories` 分类接口。
- 浏览 `/api/docs`（免登录）：**可选鉴权**——控制器 noNeedLogin 时 CheckLogin 整段跳过、uid 不会注入，已登录身份要在控制器里自行用 `JwtToken::verify(1, $token)` + SessionService 识别（tinywan 的 ACCESS_TOKEN 常量是 private，只能传字面量 1）；不可见文档详情返回 404 不泄露存在性。

## 平台设计蓝图

B2B2C（平台/服务器空间/用户三层）整体设计见 `docs/b2b2c-blueprint.md`：定位是"外部售卖插件的云端配套服务平台"+ **SSO 身份提供方**——不做商店/玩家业务门户，只有平台管理端、空间管理端和最小账号安全中心；核心机制：插件绑定码（自由生成、一插件一码可重置、超管可吊销）、游戏内绑定码（ticket 录入或 SSO 设备码，绑定即入空间）、OAuth2（授权码+PKCE 与设备码两流，client 仅超管注册，用户/空间管理员只有撤销权）、多设备会话记录与撤销（JWT 带 sid + Redis 会话校验）；**不做历史数据兼容**；QQBot/TxAdmin 自营私有不暴露。涉及空间、成员、插件、绑定码、SSO、会话、文档等新业务时先读它。
