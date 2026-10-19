<?php

namespace app\common\services;

use app\common\model\NotificationModel;
use Illuminate\Database\Capsule\Manager as DB;
use support\Redis;

/**
 * 站内通知服务（用户侧网站, ExWebsite Contract 通知端点）
 *
 * 职责：未读谓词/清理谓词查询、签名游标与短令牌、read-all/clear 幂等。
 * 输出字段名直接采用契约 camelCase（receiptId/summaryText/...），时间统一为毫秒，数据库和签名令牌仍使用秒。
 *
 * 签名格式（NotificationCursor/NotificationToken 同构）: base64url(payload) . base64url(hmac 截断)
 * payload 绑定 uid+用途+上界+过期，跨身份/跨用途复用即校验失败。
 */
class NotificationService
{
    /** readThroughToken 有效期(秒) */
    private const READ_TOKEN_TTL = 600;

    /** clearSnapshotToken 有效期(秒)——契约 10 分钟 */
    private const CLEAR_TOKEN_TTL = 600;

    /** 幂等结果保存时长(秒) */
    private const IDEMPOTENT_TTL = 600;

    // ==================== 查询 ====================

    /**
     * 分页列表（keyset: id 降序, cursor 为下界开区间）
     *
     * @param int $uid
     * @param string $view unread|history
     * @param string $scope all|community|personal
     * @param string $category 空=全部
     * @param string|null $cursor 签名游标
     * @return array{items: array, nextCursor: string|null, unreadCount: int, upperSequence: int}
     */
    public static function list(int $uid, string $view, string $scope, string $category, ?string $cursor, int $limit): array
    {
        $belowId = null;
        if ($cursor !== null) {
            $payload = self::verify($cursor, $uid, 'cursor:' . $view . ':' . $scope . ':' . $category);
            if ($payload === null) {
                throw new \app\common\exception\ApiException("分页游标无效或已过期");
            }
            $belowId = (int) ($payload['below'] ?? 0);
        }

        $q = NotificationModel::where('user_id', $uid)->where('cleared_at', null);
        if ($view === 'unread') {
            $q->where('read_at', null)->where(function ($sub) {
                $sub->where('expires_time', null)->orWhere('expires_time', '>', date('Y-m-d H:i:s'));
            });
        }
        if ($scope === 'community' || $scope === 'personal') {
            $q->where('scope', $scope);
        }
        if ($category !== '') {
            $q->where('category', $category);
        }
        if ($belowId !== null && $belowId > 0) {
            $q->where('id', '<', $belowId);
        }

        $rows = $q->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = count($rows) > $limit;
        $page = $rows->take($limit);
        $lastId = $page->isEmpty() ? 0 : (int) $page->last()->id;

        $upper = NotificationModel::where('user_id', $uid)->max('id');

        return [
            'items' => array_map([self::class, 'itemPayload'], $page->all()),
            'nextCursor' => ($hasMore && $lastId > 0)
                ? self::sign($uid, 'cursor:' . $view . ':' . $scope . ':' . $category, ['below' => $lastId], self::READ_TOKEN_TTL)
                : null,
            'unreadCount' => self::unreadCount($uid),
            'upperSequence' => (int) ($upper ?? 0),
        ];
    }

    /**
     * 未读计数（全局谓词: 未清理+未读+未过期）
     */
    public static function unreadCount(int $uid): int
    {
        return NotificationModel::where('user_id', $uid)
            ->where('cleared_at', null)
            ->where('read_at', null)
            ->where(function ($sub) {
                $sub->where('expires_time', null)->orWhere('expires_time', '>', date('Y-m-d H:i:s'));
            })
            ->count();
    }

    /**
     * 摘要（铃铛轮询）
     */
    public static function summary(int $uid): array
    {
        $upper = NotificationModel::where('user_id', $uid)->max('id');
        return [
            'unreadCount' => self::unreadCount($uid),
            'stateVersion' => (int) Redis::get("notify:sv:{$uid}") ?: 0,
            'upperSequence' => (int) ($upper ?? 0),
            'readThroughToken' => self::sign($uid, 'read-through', ['upper' => (int) ($upper ?? 0)], self::READ_TOKEN_TTL),
            'serverTime' => time() * 1000,
        ];
    }

    /**
     * 详情（含正文与动作）
     */
    public static function detail(int $uid, string $receiptId): array
    {
        $row = NotificationModel::where('uuid', $receiptId)->where('user_id', $uid)->where('cleared_at', null)->first();
        if (!$row) {
            return [];
        }
        $expired = $row->expires_time && strtotime((string) $row->expires_time) <= time();
        $contentAvailable = !empty($row->body_html) && !$expired;

        return [
            'receiptId' => $row->uuid,
            'title' => $row->title,
            'scope' => $row->scope,
            'category' => $row->category,
            'necessity' => $row->necessity,
            'sequence' => (int) $row->id,
            'deliveredAt' => (strtotime((string) $row->created_time) ?: 0) * 1000,
            'readAt' => $row->read_at !== null ? (int) $row->read_at * 1000 : null,
            'clearedAt' => null,
            'lifecycle' => $expired ? 'expired' : 'active',
            'stateRevision' => (int) $row->state_revision,
            'clearRevision' => (int) $row->clear_revision,
            'contentAvailable' => $contentAvailable,
            'content' => $contentAvailable ? [
                'format' => 'html',
                'schemaVersion' => 1,
                'policyVersion' => 'notification-html-v1',
                'html' => (string) $row->body_html,
                'text' => (string) ($row->body_text ?? ''),
            ] : null,
            'contentFallbackText' => $row->body_text !== null && $row->body_text !== '' ? (string) $row->body_text : null,
            'actions' => self::actionsPayload((array) ($row->actions ?? []), $expired),
            'actionsAvailable' => !empty((array) ($row->actions ?? [])),
            'serverTime' => time() * 1000,
        ];
    }

    // ==================== 状态写 ====================

    /**
     * 单条已读/未读（乐观锁: expectedRevision 不匹配抛 409 语义由调用方处理）
     * @return array{updated: bool, stateRevision: int}
     */
    public static function setState(int $uid, string $receiptId, bool $read, int $expectedRevision): array
    {
        $row = NotificationModel::where('uuid', $receiptId)->where('user_id', $uid)->where('cleared_at', null)->first();
        if (!$row) {
            return ['updated' => false, 'stateRevision' => 0, 'missing' => true];
        }
        if ((int) $row->state_revision !== $expectedRevision) {
            return ['updated' => false, 'stateRevision' => (int) $row->state_revision, 'conflict' => true];
        }
        $nowRead = $row->read_at !== null;
        if ($nowRead === $read) {
            // no-op 不递增修订号
            return ['updated' => true, 'stateRevision' => (int) $row->state_revision];
        }
        $row->read_at = $read ? time() : null;
        $row->state_revision = (int) $row->state_revision + 1;
        $row->save();
        self::bumpStateVersion($uid);
        return ['updated' => true, 'stateRevision' => (int) $row->state_revision];
    }

    /**
     * 全部已读（到 readThroughToken 上界为止, requestId 幂等）
     */
    public static function readAll(int $uid, string $requestId, string $readThroughToken): array
    {
        $key = "notify:idem:{$uid}:{$requestId}";
        $cached = Redis::get($key);
        if ($cached !== null) {
            $result = json_decode((string) $cached, true);
            $result['replayed'] = true;
            return $result;
        }

        $payload = self::verify($readThroughToken, $uid, 'read-through');
        if ($payload === null) {
            throw new \app\common\exception\ApiException("已读令牌无效或已过期");
        }
        $upper = (int) ($payload['upper'] ?? 0);

        $affected = NotificationModel::where('user_id', $uid)
            ->where('cleared_at', null)
            ->where('read_at', null)
            ->where('id', '<=', $upper)
            ->where(function ($sub) {
                $sub->where('expires_time', null)->orWhere('expires_time', '>', date('Y-m-d H:i:s'));
            })
            ->update([
                'read_at' => time(),
                'state_revision' => DB::raw('state_revision + 1'),
                'updated_time' => date('Y-m-d H:i:s'),
            ]);

        $result = [
            'requestId' => $requestId,
            'affectedCount' => $affected,
            'watermarkSequence' => $upper,
            'unreadCount' => self::unreadCount($uid),
            'replayed' => false,
            'serverTime' => time() * 1000,
        ];
        self::bumpStateVersion($uid);
        Redis::setex($key, self::IDEMPOTENT_TTL, json_encode($result));
        return $result;
    }

    // ==================== 清理（两段式） ====================

    /**
     * 清理预览（快照 = 当时全部未清理回执的上界与数量, 10 分钟有效）
     */
    public static function clearPreview(int $uid): array
    {
        $upper = (int) (NotificationModel::where('user_id', $uid)->where('cleared_at', null)->max('id') ?? 0);
        $count = NotificationModel::where('user_id', $uid)->where('cleared_at', null)->count();
        $batchId = self::uuid();

        return [
            'batchId' => $batchId,
            'itemCount' => $count,
            'upperSequence' => $upper,
            'clearSnapshotToken' => self::sign($uid, 'clear-snapshot', [
                'batch' => $batchId,
                'upper' => $upper,
                'count' => $count,
            ], self::CLEAR_TOKEN_TTL),
            'expiresAt' => (time() + self::CLEAR_TOKEN_TTL) * 1000,
            'serverTime' => time() * 1000,
        ];
    }

    /**
     * 执行清理（仅清上界内仍未清理的; requestId 幂等）
     */
    public static function clearAll(int $uid, string $requestId, string $clearSnapshotToken): array
    {
        $key = "notify:idem:{$uid}:{$requestId}";
        $cached = Redis::get($key);
        if ($cached !== null) {
            $result = json_decode((string) $cached, true);
            $result['replayed'] = true;
            return $result;
        }

        $payload = self::verify($clearSnapshotToken, $uid, 'clear-snapshot');
        if ($payload === null) {
            throw new \app\common\exception\ApiException("清理令牌无效或已过期");
        }

        $affected = NotificationModel::where('user_id', $uid)
            ->where('cleared_at', null)
            ->where('id', '<=', (int) ($payload['upper'] ?? 0))
            ->update([
                'cleared_at' => time(),
                'clear_revision' => DB::raw('clear_revision + 1'),
                'updated_time' => date('Y-m-d H:i:s'),
            ]);

        $result = [
            'requestId' => $requestId,
            'batchId' => (string) ($payload['batch'] ?? ''),
            'affectedCount' => $affected,
            'skippedCount' => max(0, (int) ($payload['count'] ?? 0) - $affected),
            'unreadCount' => self::unreadCount($uid),
            'replayed' => false,
            'serverTime' => time() * 1000,
        ];
        self::bumpStateVersion($uid);
        Redis::setex($key, self::IDEMPOTENT_TTL, json_encode($result));
        return $result;
    }

    // ==================== 签名工具 ====================

    /**
     * 签名短令牌: base64url(json) . base64url(hmac-sha256 截 16 字节)
     */
    private static function sign(int $uid, string $purpose, array $claims, int $ttl): string
    {
        $claims['uid'] = $uid;
        $claims['pur'] = $purpose;
        $claims['exp'] = time() + $ttl;
        $body = self::b64(json_encode($claims));
        $sig = self::b64(substr(hash_hmac('sha256', $body, self::secret(), true), 0, 16));
        return $body . '.' . $sig;
    }

    /**
     * 校验令牌: 签名+身份+用途+有效期
     * @return array|null claims, 无效返回 null
     */
    private static function verify(string $token, int $uid, string $purpose): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        $expect = self::b64(substr(hash_hmac('sha256', $body, self::secret(), true), 0, 16));
        if (!hash_equals($expect, $sig)) {
            return null;
        }
        $claims = json_decode((string) self::unb64($body), true);
        if (!is_array($claims)
            || (int) ($claims['uid'] ?? 0) !== $uid
            || ($claims['pur'] ?? '') !== $purpose
            || (int) ($claims['exp'] ?? 0) < time()) {
            return null;
        }
        return $claims;
    }

    private static function secret(): string
    {
        return (string) config('plugin.tinywan.jwt.app.jwt.access_secret_key');
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $raw): string|false
    {
        return base64_decode(strtr($raw, '-_', '+/'));
    }

    /** UUID v4（回执与发送批次共用生成器） */
  public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function bumpStateVersion(int $uid): void
    {
        Redis::incr("notify:sv:{$uid}");
        Redis::expire("notify:sv:{$uid}", 86400);
    }

    // ==================== 输出映射 ====================

    /**
     * 列表项（NotificationItem）
     */
    private static function itemPayload(NotificationModel $row): array
    {
        $expired = $row->expires_time && strtotime((string) $row->expires_time) <= time();
        return [
            'receiptId' => $row->uuid,
            'title' => $row->title,
            'summaryText' => (string) $row->summary_text,
            'scope' => $row->scope,
            'category' => $row->category,
            'necessity' => $row->necessity,
            'sequence' => (int) $row->id,
            'deliveredAt' => (strtotime((string) $row->created_time) ?: 0) * 1000,
            'readAt' => $row->read_at !== null ? (int) $row->read_at * 1000 : null,
            'expired' => (bool) $expired,
            'stateRevision' => (int) $row->state_revision,
        ];
    }

    /**
     * 动作解析（过期的导航动作禁用）
     */
    private static function actionsPayload(array $actions, bool $expired): array
    {
        $out = [];
        foreach ($actions as $a) {
            if (!is_array($a)) {
                continue;
            }
            $enabled = (bool) ($a['enabled'] ?? true) && !$expired;
            $out[] = [
                'actionId' => (string) ($a['actionId'] ?? ''),
                'kind' => (string) ($a['kind'] ?? 'navigate'),
                'label' => (string) ($a['label'] ?? ''),
                'emphasis' => (string) ($a['emphasis'] ?? 'secondary'),
                'enabled' => $enabled,
                'unavailableReason' => $enabled ? null : ($expired ? 'expired' : 'unsupported'),
                'navigationTarget' => isset($a['navigationTarget']['path'])
                    ? ['kind' => 'internal', 'path' => (string) $a['navigationTarget']['path']]
                    : null,
            ];
        }
        return $out;
    }
}
