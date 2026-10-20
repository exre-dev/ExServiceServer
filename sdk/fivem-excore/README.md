# EXCore — EXRE 平台插件运行时 SDK

FiveM 服务端 Lua SDK，为接入 EXRE 社区平台的插件提供：许可校验与心跳、离线宽限、成员权限同步、玩家绑定码、功能闸门。

HTTP 路径、请求、响应与鉴权以本 PHP 仓库手写维护的 `api/public/openapi.yaml` 为准，并沿相对引用查阅模块文件。分发时需携带完整 `api/public/` 目录；修改插件接口时同步核对本 SDK 的请求和响应处理。

## 接入方式

1. 把本目录复制为服务器的 `resources/[exre]/excore` 资源（或作为子目录并入你的插件资源，把 `excore.lua` 加进 `server_scripts`）。
2. `server.cfg` 配置：

```cfg
ensure excore
set exre_bind_code "EX-XXXXX-XXXXX-XXXXX-XXXXX"   # 空间管理端「插件与绑定码」页生成
set exre_api_base "https://exre.xiamolc.com/api"  # 平台地址（本地联调可指向 http://127.0.0.1:8787/api）
set exre_grace_hours "72"                          # 离线宽限（默认 72 小时）
set exre_heartbeat_ms "600000"                     # 心跳周期（默认 10 分钟）
```

3. 业务代码中一律通过 `exports.excore:IsAllowed(...)` 判断权限，不要自行实现校验逻辑。

## Exports

| Export | 签名 | 说明 |
|---|---|---|
| `IsAllowed` | `(src: number, featureKey?: string) -> boolean` | 玩家能否使用某功能；`featureKey=nil` 时仅判断整体授权 |
| `Features` | `(src: number) -> table<string, boolean>` | 玩家最终可用功能面 |
| `LicenseStatus` | `() -> string` | `pending/active/grace/revoked/...` 本服务器授权状态 |
| `Report` | `(event, message?, data?)` | 可选遥测上报 |

## 事件（AddEventHandler 监听）

| 事件 | 参数 | 时机 |
|---|---|---|
| `excore:OnLicenseValid` | `features` | 授权生效/心跳成功 |
| `excore:OnLicenseRevoked` | `reason`（revoked / grace_expired / server_mismatch / expired / plugin_offline / server_suspended / disabled / no_bind_code） | 授权失效——**收到 revoked 应立即停用** |
| `excore:OnGraceMode` | `remainSec` | 平台不可达但仍在宽限期内 |
| `excore:OnPermsSynced` | `count` | 成员权限表同步完成（心跳或版本号触发） |
| `excore:OnPlayerPermsApplied` | `src, perm` | 玩家进服（`perm=nil` 表示未绑定） |

## 权限同步机制（v1.1.0+）

- 常规：心跳（默认 10 分钟）顺带拉取权限表。
- 快速：SDK 每 30 秒查询权限版本号 `perm/rev`；空间管理员在「成员权限」页点**下发权限**后，版本号 +1，SDK 数秒内自动拉取全量。旧版 SDK（≤1.0）无此通道，只能等心跳。

## 业务接入示例

```lua
-- 你的插件业务: 氮气功能
RegisterNetEvent('mytuner:useNitro', function()
  local src = source
  if not exports.excore:IsAllowed(src, 'nitro') then
    TriggerClientEvent('chat:addMessage', src, { args = { 'Tuner', '你没有氮气功能权限' } })
    return
  end
  -- ...执行氮气逻辑
end)

-- 监听授权失效整体停用
AddEventHandler('excore:OnLicenseRevoked', function(reason)
  if reason == 'revoked' then StopResource(GetCurrentResourceName()) end
end)
```

## 玩家绑定流程

1. 玩家游戏内输入 `/exbind` → 屏显 6 位绑定码（5 分钟有效，一次性）。
2. 管理员在空间管理端「游戏绑定」录入绑定码并选择归属用户 → 绑定成功，玩家自动加入空间。
3. 下一次心跳（≤10 分钟）权限表同步后生效；`/exstatus` 可随时自查。

## 安全说明

- 平台只保存 `sv_licenseKey` 的 **SHA-256 哈希**（首次校验时锁定），更换服务器需在空间管理端**重置绑定码**。
- 绑定码即服务凭证，泄露请立即在管理端重置。
- 平台短时不可达时本地宽限运行（默认 72h，`grace.json` 持久化）；**平台明确吊销（revoked）不进宽限，立即停用**。
