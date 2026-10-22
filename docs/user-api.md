# 用户侧 API 对接文档（账号能力）

> 版本：2026-10-17。适用对象：用户侧 UI（网页/客户端）开发方。
> 覆盖：发送邮箱验证码、注册、登录、获取当前用户信息、登出。账号安全中心其余接口（设备管理/第三方授权/改密）见文末附录。

## 1. 通用约定

| 项 | 约定 |
|---|---|
| Base URL | 生产 `https://<平台域名>/api`；本地联调 `http://127.0.0.1:8787/api` |
| 用户侧 UI | 父工程 ExWebsite 维护的独立用户网站，启动方式见父工程 README。 |
| 请求格式 | `Content-Type: application/json`，UTF-8 |
| 响应包络 | **成功** HTTP 200，`{"msg": "ok 或文案", "data": {...}}`；**失败** HTTP 4xx，`{"msg": "中文错误信息"}`（无 data 字段） |
| 认证方式 | 请求头 `Authorization: Bearer <token>`；token 即登录/注册返回的本站 `token` 字段。OAuth 访问令牌仅用于 OAuth 资源接口，不能用于本站账号或管理接口（401） |
| token 有效期 | 返回值 `expires_in` 秒（当前 36000 = 10 小时）；**无 refresh token**，过期重新登录 |
| 多设备 | 每次登录生成独立会话，多设备并存；服务端可撤销任一设备 |
| **401** | 登录态失效（过期/被撤销/被封禁下线）→ **清除本地 token 并跳转登录页**，`msg` 可直接展示 |
| 403 | 身份有效但被拒（如账号被封禁）；400 = 普通业务错误 |
| 限流 | 全局按 IP：30 秒内 5 次失败请求会被拦截（403 "请求过于频繁"）；发送验证码另有每邮箱 60 秒冷却 + 每 IP 每分钟 5 次限制 |
| CORS | 已开放任意 Origin（Authorization 头方式，无 Cookie 依赖）；**生产建议 UI 与 API 同域部署**（网关只转发 `/api`） |

错误响应统一示例：

```json
HTTP 400
{"msg": "邮箱或密码错误"}
```

---

## 2. 发送邮箱验证码

注册流程可选拼图：向邮箱发送 6 位数字验证码（10 分钟有效）。**一旦某邮箱发送过验证码，该邮箱注册时必须携带正确验证码**；未发送过的邮箱注册可省略（过渡期策略，正式上线后强制）。

```
POST /api/account/send-email-code
```

**请求体**

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| email | string | 是 | 合法邮箱地址 |

**请求示例**

```json
{"email": "player@example.com"}
```

**成功响应**

```json
HTTP 200
{"msg": "验证码已发送至邮箱, 10 分钟内有效", "data": []}
```

**错误响应**

| HTTP | msg | 场景 |
|---|---|---|
| 400 | 邮箱格式不正确 | email 非法 |
| 400 | 发送过于频繁,请 N 秒后再试 | 同邮箱 60 秒冷却（N 为剩余秒数，UI 可做倒计时） |
| 400 | 发送过于频繁,请稍后再试! | 同 IP 每分钟超过 5 次 |
| 400 | 邮件发送失败,请稍后再试或联系管理员 | SMTP 故障（生产） |

**联调特殊行为**：平台 `debug` 模式且邮件发送失败时，响应 `data.debug_code` 直接携带验证码（便于本地未配 SMTP 联调）；生产永远不返回验证码。

```json
{"msg": "邮件发送失败(未配置SMTP?), debug 模式直接返回验证码", "data": {"debug_code": "482913"}}
```

---

## 3. 注册

```
POST /api/account/register
```

**请求体**

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| email | string | 是 | 邮箱，全局唯一 |
| password | string | 是 | ≥ 6 位 |
| code | string | 条件 | 邮箱验证码；该邮箱发送过验证码则必填且须匹配 |
| username | string | 否 | 昵称，缺省取邮箱 @ 前缀 |
| qq | string | 否 | 纯数字，全局唯一 |

**成功响应（注册即登录，直接返回 token）**

```json
HTTP 200
{
  "msg": "注册成功",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9....",
    "expires_in": 36000,
    "user": {
      "id": 12,
      "email": "player@example.com",
      "username": "player",
      "avatar": "/app/user/default-avatar.png",
      "platform_role": ""
    }
  }
}
```

**错误响应**

| HTTP | msg | 场景 |
|---|---|---|
| 400 | 参数不匹配 | body 为空或缺少 email/password |
| 400 | 邮箱格式不正确 / 密码至少6位 / QQ必须是纯数字 | 校验失败 |
| 400 | 邮箱已被注册 / QQ已被绑定 | 唯一性冲突 |
| 400 | 校验码不匹配或已过期 | 发送过验证码但 code 错误/缺失 |

---

## 4. 登录

```
POST /api/account/login
```

**请求体**

| 字段 | 类型 | 必填 |
|---|---|---|
| email | string | 是 |
| password | string | 是 |

**成功响应（每次登录生成独立会话，多设备并存）**

```json
HTTP 200
{
  "msg": "ok",
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9....",
    "expires_in": 36000,
    "user": {
      "id": 12,
      "email": "player@example.com",
      "username": "player",
      "avatar": "/app/user/default-avatar.png",
      "platform_role": ""
    }
  }
}
```

**错误响应**

| HTTP | msg | 场景 |
|---|---|---|
| 400 | 邮箱或密码不能为空 | 缺参 |
| 400 | 邮箱或密码错误 | 凭证错误（不区分"账号不存在"，防探测） |
| 400 | 当前账户已经被禁用 | 封禁 |

> 前端建议：登录失败按 `msg` 展示即可；连续失败注意全局限流（30 秒 5 次）。

---

## 5. 获取当前用户信息

```
GET /api/account/me
Authorization: Bearer <token>
```

**成功响应**

```json
HTTP 200
{
  "msg": "ok",
  "data": {
    "id": 12,
    "email": "player@example.com",
    "username": "player",
    "avatar": "/app/user/default-avatar.png",
    "qq": "123456",
    "platform_role": "",
    "last_login_time": "2026-10-17 15:20:31"
  }
}
```

`platform_role`：空串 = 普通用户；`ops` = 平台运营；`superadmin` = 超管（用户侧 UI 无需处理，仅透传）。

**错误响应**：`401 {"msg":"登录状态已失效,请重新登录"}` → 清 token 跳登录页。

---

## 6. 登出（建议一并对接）

```
POST /api/account/logout
Authorization: Bearer <token>
```

成功：`200 {"msg":"已退出登录","data":[]}`。服务端会撤销当前会话（token 立即失效）；前端随后清除本地 token。未带 token 调用也返回 200（幂等）。

---

## 7. 联调速查

```bash
# 登录
curl -X POST http://127.0.0.1:8787/api/account/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"123456"}'

# me
curl http://127.0.0.1:8787/api/account/me -H "Authorization: Bearer <token>"

# 发送验证码（本地未配 SMTP 时 data.debug_code 直接可用）
curl -X POST http://127.0.0.1:8787/api/account/send-email-code \
  -H "Content-Type: application/json" -d '{"email":"a@b.com"}'
```

测试账号：`test@example.com / 123456`（空间 Owner）、`p0-test@example.com / abc123`（普通成员）、`admin@exre.local / Admin@2026`（平台超管，勿用于用户侧联调）。

时间格式统一 `Y-m-d H:i:s`（服务器时区 Asia/Shanghai）。头像为相对路径，完整 URL = 平台静态域名 + 路径。

---

## 附录：账号安全中心接口（可选对接）

均已要求登录态，包络与错误码约定同上：

| 接口 | 说明 |
|---|---|
| `GET /api/account/sessions` | 我的登录设备列表（`current` 标记当前会话） |
| `POST /api/account/sessions/{sid}/revoke` | 下线指定设备 |
| `POST /api/account/sessions/revoke-all` | 下线除当前外全部设备 |
| `GET /api/account/grants` | 我授权的第三方应用列表 |
| `POST /api/account/grants/{client_id}/revoke` | 撤销某应用授权（其 token 立即失效） |
| `POST /api/account/password` | 改密 `{old_password,new_password}`（成功后其他设备全部下线，需重新登录） |
