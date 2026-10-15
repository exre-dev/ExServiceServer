<?php

namespace app\common\services;

use app\common\model\AuditLogModel;

/**
 * 审计日志服务（敏感写操作全量记录, 蓝图 §4.9）
 */
class AuditService
{
    /**
     * 记录一条审计日志
     *
     * @param int|null $actorUserId 操作者用户 id（系统操作传 null）
     * @param string $actorType platform/server/user/client
     * @param string $action 动作名, 如 user.login / binding.revoke / client.create
     * @param array $opts server_id/client_id/target_type/target_id/detail
     */
    public static function log(?int $actorUserId, string $actorType, string $action, array $opts = []): void
    {
        try {
            $log = new AuditLogModel();
            $log->actor_user_id = $actorUserId;
            $log->actor_type = $actorType;
            $log->server_id = $opts['server_id'] ?? null;
            $log->client_id = $opts['client_id'] ?? null;
            $log->action = $action;
            $log->target_type = $opts['target_type'] ?? '';
            $log->target_id = (string) ($opts['target_id'] ?? '');
            $log->detail = $opts['detail'] ?? null;
            $log->ip = self::currentIp();
            $log->created_time = date('Y-m-d H:i:s');
            $log->save();
        } catch (\Throwable $e) {
            // 审计失败不阻断业务, 但必须留痕
            \support\Log::error('审计日志写入失败: ' . $e->getMessage());
        }
    }

    private static function currentIp(): string
    {
        try {
            $request = request();
            return $request ? ($request->getRealIp() ?: '') : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
