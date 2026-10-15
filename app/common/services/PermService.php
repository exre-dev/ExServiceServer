<?php

namespace app\common\services;

use app\common\model\MemberPluginPermModel;
use app\common\model\PluginBindingModel;
use app\common\model\PluginFeatureModel;
use app\common\model\PluginModel;
use app\common\model\ServerAccountModel;
use app\common\model\ServerMemberModel;
use support\Redis;

/**
 * 插件功能权限判定服务（三层漏斗统一入口）
 *
 * 判定公式（蓝图 §4.5）:
 *   授权有效 = binding(active) && plugin(online) && server(active) && 未过期
 *   允许使用 = 授权有效 && member(active) && (perm记录存在 ? perm.allowed : true)
 *   最终功能 = binding.features 功能面 ∧ (perm.features ?? 全部)
 *
 * 管理端展示与 /open 权限下发共用本入口，保证口径一致。
 */
class PermService
{
    /**
     * 解析绑定码的服务器级功能面（空对象 = 插件全部默认开启功能）
     * /open validate 与 canUse 共用, 保证口径一致
     *
     * @return array<string, bool>
     */
    public static function serverFeatures(PluginBindingModel $binding): array
    {
        $features = $binding->features;
        if (is_array($features) && $features !== []) {
            return $features;
        }
        return PluginFeatureModel::where('plugin_id', $binding->plugin_id)
            ->where('default_enabled', true)
            ->pluck('feature_key')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * 判定用户在某空间对某插件的使用权限与最终功能面
     *
     * @param int $userId 用户 id
     * @param int $serverId 空间 id
     * @param string $pluginSlug 插件 slug
     * @return array{allowed: bool, features: array<string, bool>}|null 授权无效返回 null
     */
    public static function canUse(int $userId, int $serverId, string $pluginSlug): ?array
    {
        // ---- 1. 授权链: 绑定码 + 插件状态 + 空间状态 ----
        $binding = PluginBindingModel::where('server_id', $serverId)
            ->whereHas('plugin', function ($q) use ($pluginSlug) {
                $q->where('slug', $pluginSlug);
            })
            ->first();
        if (!$binding || $binding->status !== 'active') {
            return null;
        }
        if ($binding->plugin && $binding->plugin->status !== 'online') {
            return null;
        }
        if ($binding->expires_time && strtotime((string) $binding->expires_time) < time()) {
            return null;
        }
        $server = ServerAccountModel::find($serverId);
        if (!$server || $server->status !== 'active') {
            return null;
        }

        // ---- 2. 成员关系 ----
        $member = ServerMemberModel::where('server_id', $serverId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();
        if (!$member) {
            return null;
        }

        // ---- 3. 服务器级功能面 ----
        $serverFeatures = self::serverFeatures($binding);

        // ---- 4. 用户级覆盖 ----
        $perm = MemberPluginPermModel::where('server_id', $serverId)
            ->where('user_id', $userId)
            ->where('plugin_id', $binding->plugin_id)
            ->first();

        $allowed = $perm ? (bool) $perm->allowed : true;
        $features = $serverFeatures;
        if ($perm && is_array($perm->features) && $perm->features !== []) {
            // 在服务器功能面内收窄: 服务器开 && 用户开 才为 true
            $features = [];
            foreach ($serverFeatures as $key => $on) {
                $features[$key] = $on && (bool) ($perm->features[$key] ?? false);
            }
        }

        return [
            'allowed' => $allowed,
            'features' => $features,
        ];
    }

    /**
     * 清除某空间某插件的许可/权限缓存（吊销、改权限、改功能面后调用, 下次心跳立即生效）
     */
    public static function clearCache(int $serverId, string $pluginSlug): void
    {
        Redis::del("lic:{$serverId}:{$pluginSlug}");
        Redis::del("perm:{$serverId}:{$pluginSlug}");
    }

    /**
     * 清除某空间全部插件的缓存（空间暂停/恢复时调用）
     */
    public static function clearCacheForServer(int $serverId): void
    {
        $bindings = PluginBindingModel::where('server_id', $serverId)
            ->with('plugin:id,slug')
            ->get();
        foreach ($bindings as $binding) {
            if ($binding->plugin) {
                self::clearCache($serverId, (string) $binding->plugin->slug);
            }
        }
    }

    /**
     * 按 FiveM license 标识判定（/open 接口用: ident → user → canUse）
     */
    public static function canUseByIdent(string $ident, int $serverId, string $pluginSlug): ?array
    {
        $identRow = \app\common\model\UserIdentModel::where('ident_type', 'fivem_license')
            ->where('ident_value', $ident)
            ->first();
        if (!$identRow) {
            return null;
        }
        return self::canUse((int) $identRow->user_id, $serverId, $pluginSlug);
    }
}
