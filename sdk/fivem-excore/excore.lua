--[[
EXCore — EXRE 平台插件运行时 SDK (服务端 Lua) v1.1.0

七个模块:
  1 License    启动校验 + 600s 心跳 + 72h 离线宽限 + 吊销即时停用
  2 Heartbeat  心跳顺带拉取成员权限表
  3 Binder     /exbind 生成 6 位绑定码, /exstatus 查询本人状态
  4 PermSync   ident → 权限 内存表, 玩家进服即应用
  5 RevWatch   每 30s 查询权限版本号(perm/rev), 变化即拉全量——
               空间管理端「下发权限」按钮的秒级手动同步通道(v1.1 新增)
  6 FeatureGate exports IsAllowed/Features/LicenseStatus —— 业务代码唯一判断入口
  7 Telemetry  exports Report 可选上报

convar 配置 (server.cfg):
  set exre_bind_code "EX-XXXXX-XXXXX-XXXXX-XXXXX"   -- 平台空间管理端生成的绑定码
  set exre_api_base "https://exre.xiamolc.com/api"  -- 平台 API 地址(默认)
  set exre_grace_hours "72"                          -- 离线宽限小时数
  set exre_heartbeat_ms "600000"                     -- 心跳周期毫秒

事件回调 (业务资源 AddEventHandler 监听):
  excore:OnLicenseValid(features)      授权生效
  excore:OnLicenseRevoked(reason)      授权失效(revoked=平台吊销, grace_expired=宽限超时)
  excore:OnGraceMode(remainSec)        进入宽限模式
  excore:OnPermsSynced(count)          权限表同步完成
  excore:OnPlayerPermsApplied(src, perm) 玩家进服权限应用(perm 可能为 nil=未绑定)
]]

-- ==================== 配置 ====================
local BIND_CODE     = GetConvar('exre_bind_code', '')
local API_BASE      = (GetConvar('exre_api_base', 'https://exre.xiamolc.com/api')):gsub('/$', '')
local GRACE_HOURS   = tonumber(GetConvar('exre_grace_hours', '72')) or 72
local HEARTBEAT_MS  = tonumber(GetConvar('exre_heartbeat_ms', '600000')) or 600000

-- ==================== SHA-256 (纯 Lua 5.4, 用于 sv_licenseKey 哈希) ====================
local function sha256(msg)
  local K = {
    0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,
    0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
    0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,
    0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
    0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,
    0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
    0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,
    0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2,
  }
  local H = { 0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19 }
  local W = {}
  local len = #msg
  msg = msg .. "\128" .. string.rep("\0", (55 - len) % 64) .. string.pack(">I8", len * 8)
  for chunk = 1, #msg, 64 do
    local s = msg:sub(chunk, chunk + 63)
    for i = 0, 15 do W[i + 1] = string.unpack(">I4", s, 4 * i + 1) end
    for i = 16, 63 do
      local w15, w2 = W[i - 15 + 1], W[i - 2 + 1]
      local s0 = ((w15 >> 7) | (w15 << 25)) ~ ((w15 >> 18) | (w15 << 14)) ~ (w15 >> 3)
      local s1 = ((w2 >> 17) | (w2 << 15)) ~ ((w2 >> 19) | (w2 << 13)) ~ (w2 >> 10)
      W[i + 1] = (W[i - 16 + 1] + s0 + W[i - 7 + 1] + s1) & 0xffffffff
    end
    local a, b, c, d, e, f, g, h = table.unpack(H)
    for i = 1, 64 do
      local S1 = ((e >> 6) | (e << 26)) ~ ((e >> 11) | (e << 21)) ~ ((e >> 25) | (e << 7))
      local ch = (e & f) ~ (~e & g)
      local t1 = (h + S1 + ch + K[i] + W[i]) & 0xffffffff
      local S0 = ((a >> 2) | (a << 30)) ~ ((a >> 13) | (a << 19)) ~ ((a >> 22) | (a << 10))
      local mj = (a & b) ~ (a & c) ~ (b & c)
      local t2 = (S0 + mj) & 0xffffffff
      h, g, f, e, d, c, b, a = g, f, e, (d + t1) & 0xffffffff, c, b, a, (t1 + t2) & 0xffffffff
    end
    local v = { a, b, c, d, e, f, g, h }
    for i = 1, 8 do H[i] = (H[i] + v[i]) & 0xffffffff end
  end
  local out = {}
  for i = 1, 8 do out[i] = string.format("%08x", H[i]) end
  return table.concat(out)
end

-- 平台只见哈希, 不接触 sv_licenseKey 明文
local SERVER_KEY_HASH = sha256(GetConvar('sv_licenseKey', ''))

-- ==================== 状态 ====================
local license = { status = 'pending', features = {}, expires_time = nil, sync_token = '' }
local identPerms = {}   -- [license:xxx] = { allowed = bool, features = {k:bool} }
local lastOkAt = nil    -- 最近一次成功校验的 os.time (宽限基准)

-- ==================== HTTP ====================
local function Api(method, path, body, cb)
  local url = API_BASE .. path
  local payload = body and json.encode(body) or '{}'
  PerformHttpRequest(url, function(statusCode, resBody, headers)
    local ok, decoded = pcall(json.decode, resBody or '')
    if cb then cb(statusCode == 200 and ok, statusCode, (ok and decoded or nil)) end
  end, method, payload, {
    ['Content-Type'] = 'application/json',
    ['X-Bind-Code'] = BIND_CODE,
  })
end

-- ==================== 宽限持久化 (资源目录 grace.json) ====================
local RES = GetCurrentResourceName()
local function SaveGrace()
  SaveResourceFile(RES, 'grace.json', json.encode({ last_ok_at = lastOkAt }), -1)
end
local function LoadGrace()
  local raw = LoadResourceFile(RES, 'grace.json')
  if raw then
    local ok, data = pcall(json.decode, raw)
    if ok and data and data.last_ok_at then lastOkAt = data.last_ok_at end
  end
end

-- ==================== 模块4: PermSync ====================
local function FetchPerms()
  Api('GET', '/open/perm/batch', nil, function(ok, code, res)
    if ok and code == 200 and res and res.data and res.data.list then
      local next = {}
      for _, row in ipairs(res.data.list) do
        next[row.ident] = { allowed = row.allowed == true, features = row.features or {} }
      end
      identPerms = next
      TriggerEvent('excore:OnPermsSynced', #res.data.list)
    end
  end)
end

-- ==================== 模块1: License 校验/心跳 ====================
local function Validate()
  if BIND_CODE == '' then
    license.status = 'no_bind_code'
    print('^3[EXCore] 未配置 exre_bind_code, 插件功能停用^0')
    TriggerEvent('excore:OnLicenseRevoked', 'no_bind_code')
    return
  end
  Api('POST', '/open/license/validate', {
    bind_code = BIND_CODE,
    server_key_hash = SERVER_KEY_HASH,
  }, function(ok, code, res)
    if ok and code == 200 and res and res.data then
      local d = res.data
      license.status = d.status
      license.features = d.features or {}
      license.expires_time = d.expires_time
      license.sync_token = d.sync_token or ''
      if d.status == 'active' then
        lastOkAt = os.time()
        SaveGrace()
        TriggerEvent('excore:OnLicenseValid', license.features)
        FetchPerms() -- 模块2: 心跳顺带同步权限
      elseif d.status == 'revoked' then
        -- 平台明确吊销: 立即停用, 不进宽限
        TriggerEvent('excore:OnLicenseRevoked', 'revoked')
      else
        -- server_mismatch / expired / plugin_offline / server_suspended / disabled
        TriggerEvent('excore:OnLicenseRevoked', d.status)
      end
    else
      -- 网络失败: 走离线宽限
      local remain = lastOkAt and (os.time() - lastOkAt)
      if remain and remain <= GRACE_HOURS * 3600 then
        license.status = 'grace'
        TriggerEvent('excore:OnGraceMode', GRACE_HOURS * 3600 - remain)
      else
        license.status = 'grace_expired'
        TriggerEvent('excore:OnLicenseRevoked', 'grace_expired')
      end
    end
  end)
end

-- ==================== 模块5: RevWatch（权限版本号监视, v1.1） ====================
-- 每 30s 查询一次权限版本号, 变化即拉取全量权限表。
-- 空间管理端「下发权限」= 清缓存 + 版本号+1 → 本地数秒内完成同步;
-- 不手动下发则等 600s 心跳顺带同步。
local lastPermRev = nil

local function WatchPermRev()
  CreateThread(function()
    while true do
      Wait(30000)
      if license.status == 'active' or license.status == 'grace' then
        Api('GET', '/open/perm/rev', nil, function(ok, code, res)
          if ok and code == 200 and res and res.data then
            local rev = tonumber(res.data.rev) or 0
            if lastPermRev == nil then
              lastPermRev = rev -- 首次只建立基线
            elseif rev ~= lastPermRev then
              lastPermRev = rev
              FetchPerms()
            end
          end
        end)
      end
    end
  end)
end

-- ==================== 模块6: FeatureGate (业务唯一入口) ====================
local function PlayerIdent(src)
  for _, id in ipairs(GetPlayerIdentifiers(src)) do
    if id:sub(1, 8) == 'license:' then return id end
  end
  return nil
end

exports('IsAllowed', function(src, featureKey)
  -- 授权闸门: active 或宽限内可用
  if license.status ~= 'active' and license.status ~= 'grace' then return false end
  local perm = identPerms[PlayerIdent(src) or '']
  if not perm or not perm.allowed then return false end
  if featureKey == nil then return true end
  return perm.features[featureKey] == true
end)

exports('Features', function(src)
  local perm = identPerms[PlayerIdent(src) or '']
  return (perm and perm.allowed) and perm.features or {}
end)

exports('LicenseStatus', function() return license.status end)

-- 玩家进服: 应用其权限并广播事件
AddEventHandler('playerJoining', function()
  local src = source
  local perm = identPerms[PlayerIdent(src) or '']
  TriggerEvent('excore:OnPlayerPermsApplied', src, perm)
end)

-- ==================== 模块3: Binder ====================
RegisterCommand('exbind', function(source)
  if source == 0 then return end -- 服务器控制台无游戏标识
  local src = source
  local ident = PlayerIdent(src)
  if not ident then
    TriggerClientEvent('chat:addMessage', src, { args = { 'EXCore', '未获取到你的 license 标识' } })
    return
  end
  Api('POST', '/open/ident/bind-ticket', { ident = ident, ident_type = 'fivem_license' }, function(ok, code, res)
    if ok and code == 200 and res and res.data and res.data.ticket then
      TriggerClientEvent('chat:addMessage', src, {
        args = { 'EXCore', ('你的绑定码: ^2%s^0 （5 分钟内有效, 请发给服务器管理员完成绑定）'):format(res.data.ticket) },
      })
    else
      TriggerClientEvent('chat:addMessage', src, { args = { 'EXCore', '绑定码生成失败(空间授权可能已失效), 请联系管理员' } })
    end
  end)
end, false)

RegisterCommand('exstatus', function(source)
  if source == 0 then
    print(('[EXCore] license=%s features=%s'):format(license.status, json.encode(license.features)))
    return
  end
  local perm = identPerms[PlayerIdent(source) or '']
  if not perm then
    TriggerClientEvent('chat:addMessage', source, { args = { 'EXCore', '你尚未绑定社区账号, 输入 /exbind 获取绑定码' } })
    return
  end
  TriggerClientEvent('chat:addMessage', source, {
    args = { 'EXCore', ('状态:%s 可用功能:%s'):format(perm.allowed and '已授权' or '被禁用', json.encode(perm.features)) },
  })
end, false)

-- ==================== 模块7: Telemetry ====================
exports('Report', function(event, message, data)
  Api('POST', '/open/telemetry', { event = event, message = message, data = data }, function() end)
end)

-- ==================== 启动 ====================
CreateThread(function()
  LoadGrace()
  Wait(1000)
  Validate()
  WatchPermRev() -- v1.1: 权限版本号快速同步通道
  while true do
    Wait(HEARTBEAT_MS)
    Validate()
  end
end)
