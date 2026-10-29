<?php
declare(strict_types=1);

/** 監査ログは呼び出し元のトランザクション内で保存する。 */
function write_audit_log(PDO $pdo, int $actorId, string $action, string $targetType, int $targetId, array $details): void
{
    $actions = [
        'reservation.create' => 'reservation', 'reservation.cancel' => 'reservation',
        'reservation.approve' => 'reservation', 'reservation.reject' => 'reservation',
        'reservation.lend' => 'reservation', 'reservation.return' => 'reservation',
        'item.create' => 'item', 'item.update' => 'item', 'item.deactivate' => 'item',
    ];
    if (($actions[$action] ?? null) !== $targetType || $actorId < 1 || $targetId < 1 || !$pdo->inTransaction()) {
        throw new LogicException('Invalid audit event');
    }
    // 内容は定義済みの数値・状態のみ保存し、入力文字列や秘密情報を記録しない。
    $safe = [];
    foreach (['user_id', 'item_id', 'before_status', 'after_status'] as $key) {
        if (!array_key_exists($key, $details)) {
            continue;
        }
        $value = $details[$key];
        if (in_array($key, ['user_id', 'item_id'], true) && is_int($value) && $value > 0) {
            $safe[$key] = $value;
        } elseif (in_array($key, ['before_status', 'after_status'], true)
            && is_string($value)
            && in_array($value, ['pending', 'approved', 'lent', 'returned', 'rejected', 'canceled', 'available', 'maintenance', 'inactive'], true)) {
            $safe[$key] = $value;
        }
    }
    $statement = $pdo->prepare('INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$actorId, $action, $targetType, $targetId, json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
}
