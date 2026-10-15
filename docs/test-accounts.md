# 测试账号与手工测试手册

> 更新：2026-10-17（P4 完成后）。本文档与本地测试库实际状态一致；执行 `database/schema.sql + seed.sql` 重建后回到初始状态（仅剩前两个账号 + 无业务数据）。

## 1. 环境启动（三件套）

```bash
docker start webman-pgsql          # PostgreSQL 16, 127.0.0.1:5432
cd F:\CAEP-Ending\webman && php windows.php     # 后端, http://127.0.0.1:8787
cd F:\CAEP-Ending\webman\web\admin-web && npm run dev   # 管理端, http://127.0.0.1:5173
# 用户侧 UI（独立项目）: cd web\user-web && pnpm dev → http://127.0.0.1:8080
```

Redis 要求本机 6379（Windows 服务）。`config/app.php` 的 `debug=true` 时本机请求不受 IP 限流。

## 2. 测试账号

| 邮箱 | 密码 | 身份 | 用途 |
|---|---|---|---|
| `admin@exre.local` | `Admin@2026` | 平台超级管理员 | 平台管理端全部功能 |
| `test@example.com` | `123456` | 普通用户，**demo-server 的 Owner** | 空间管理端全部功能 |
| `p0-test@example.com` | `abc123` | 普通用户，demo-server 的 **member** | 验证成员角色无管理权限、被权限矩阵限制功能 |

登录入口：管理端 http://127.0.0.1:5173/login ；不同账号登录看到不同菜单（平台角色看平台菜单，空间 Owner 直接进空间控制台）。

## 3. 当前测试数据状态

| 数据 | 值 |
|---|---|
| 空间 | `Demo Server`（slug `demo-server`，id=1，active，Owner=test@example.com） |
| 成员 | test（owner）/ p0-test（member，手动添加）/ |
| 插件 | `EX Tuner`（slug `ex-tuner`，online），功能点 `nitro`、`dyno_edit`（默认全开） |
| 绑定码 | 以空间端「插件与绑定码」页当前显示为准（手工测试中可能被重置过；重置后旧码立即失效） |
| 游戏标识 | `license:abc123def456` → p0-test（权限矩阵里只开了 `nitro`） |
| 会话失效演示 | p0-test 的 `dyno_edit` 被关，`PermService::canUse` 返回 `{"nitro":true,"dyno_edit":false}` |

### OAuth 测试应用（密钥为最近一次重置的明文，仅文档记录这一份）

| 应用 | client_id | client_secret | 类型 |
|---|---|---|---|
| P4测试平台 | `e062439a175486316847d3c4ec37b82f` | `17c82e5d8894694885dc0f93a76ba5fb5c39a671431c2981` | third_party，回调白名单 `http://localhost:5999/callback`，scopes: user.basic/user.email/servers.read |
| EX Tuner Game Plugin | `89d12ac72ac02a026d16631157118e6c` | `5ae314bb78467cdba3caf8354d341ad38655cf45d361f6a0` | plugin，归属 demo-server，scopes: user.basic/idents.read/perms.read |

> 密钥重置入口：平台管理端 → 第三方应用 → 重置密钥（旧密钥立即失效；重置后需同步更新本文档）。

## 4. 手工测试场景

### 4.1 平台管理端（用 admin 登录）

1. **概览**：看用户/空间/插件/绑定码统计与最近审计。
2. **空间管理**：新建空间（Owner 填 `p0-test@example.com` 试试）→ 暂停 → 空间端绑定码授权立即冻结 → 恢复。
3. **用户管理**：搜索 `test@example.com` → 详情看会话设备与加入空间 → 封禁（会话全下线）→ 解封。
4. **插件目录**：建新插件 → 加功能点 → 上线 → 下架看绑定码校验变化。
5. **绑定码监管**：看健康度标记；吊销任意绑定码。
6. **审计日志**：按动作前缀（`server.` / `user.` / `binding.` / `oauth.`）筛选。

### 4.2 空间管理端（用 test@example.com 登录，自动进 Demo Server）

1. **成员管理**：添加 `p0-test@example.com`（已存在会提示重复）→ 调整角色 → 禁用/解禁。
2. **游戏绑定**：模拟玩家 ticket——
   ```bash
   # 先拿绑定码（空间端 → 插件与绑定码 → 重置拿新码）
   curl -X POST http://127.0.0.1:8787/api/open/ident/bind-ticket \
     -H "X-Bind-Code: <新绑定码>" -H "Content-Type: application/json" \
     -d '{"ident":"license:manual-test-001"}'
   # 返回 {ticket:"xxxxxx"}，5 分钟内到「游戏绑定」页录入并选择用户
   ```
3. **成员权限**：选 ex-tuner → 关掉 p0-test 的 `dyno_edit` → 保存 → 验证 `/api/open/perm/batch`。
4. **应用授权**：断开 EX Tuner Game Plugin → 恢复。

### 4.3 SSO 授权码流（第三方平台视角）

浏览器打开（登录 test@example.com 后点"同意授权"）：

```
http://127.0.0.1:8787/oauth/authorize?client_id=e062439a175486316847d3c4ec37b82f&redirect_uri=http://localhost:5999/callback&scope=user.basic%20user.email%20servers.read&state=demo&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256
```

（上面 challenge 对应 verifier `dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk`，标准 RFC 7636 示例值）

回调拿到 `code` 后换令牌：

```bash
curl -X POST http://127.0.0.1:8787/api/oauth/token -H "Content-Type: application/json" \
  -d '{"grant_type":"authorization_code","code":"<回调拿到的code>","client_id":"e062439a175486316847d3c4ec37b82f","client_secret":"17c82e5d8894694885dc0f93a76ba5fb5c39a671431c2981","redirect_uri":"http://localhost:5999/callback","code_verifier":"dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"}'

# 拿 access_token 后查用户信息（注意 scope 过滤）
curl http://127.0.0.1:8787/api/open/userinfo -H "Authorization: Bearer <access_token>"
```

撤销验证：登录任意账号 → 右上角头像 → 账号安全 → 第三方授权 → 撤销 → 再调 userinfo 应 401。

### 4.4 SSO 设备码流（插件视角）

```bash
# 1. 插件发起
curl -X POST http://127.0.0.1:8787/api/oauth/device/init -H "Content-Type: application/json" \
  -d '{"client_id":"89d12ac72ac02a026d16631157118e6c","client_secret":"5ae314bb78467cdba3caf8354d341ad38655cf45d361f6a0"}'
# → {device_code, user_code}

# 2. 玩家任意设备打开 http://127.0.0.1:8787/oauth/device 登录并输入 user_code

# 3. 插件轮询换令牌
curl -X POST http://127.0.0.1:8787/api/oauth/device/token -H "Content-Type: application/json" \
  -d '{"device_code":"<device_code>","client_id":"89d12ac72ac02a026d16631157118e6c","client_secret":"5ae314bb78467cdba3caf8354d341ad38655cf45d361f6a0"}'

# 4. 玩家自绑定（绑定即入 demo-server）
curl -X POST http://127.0.0.1:8787/api/open/ident/bind-self -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" -d '{"ident":"license:my-game-license"}'
```

### 4.5 插件心跳与权限下发（模拟 EXCore）

```bash
# 绑定码须先在空间端重置为未锁定状态
curl -X POST http://127.0.0.1:8787/api/open/license/validate -H "Content-Type: application/json" \
  -d '{"bind_code":"<绑定码>","server_key_hash":"<64位哈希，首次校验会锁定>"}'

curl http://127.0.0.1:8787/api/open/perm/batch -H "X-Bind-Code: <绑定码>"
```

## 5. 数据重置

```bash
# 清库重建（幂等，会清空全部测试数据）
docker exec -i webman-pgsql psql -U webman -d webman < database/schema.sql
docker exec -i webman-pgsql psql -U webman -d webman < database/seed.sql
# 重建后需重新创建空间/插件/应用等测试数据（可参照本文档第 3 节）
```

旧库备份：`runtime/backup/pre-p0-20261017.sql`。
