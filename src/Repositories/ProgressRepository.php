<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class ProgressRepository
{
    public function getUserXp(int $userId): int
    {
        $stmt = Db::pdo()->prepare("SELECT xp FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function addXpToUser(int $userId, int $delta): void
    {
        $stmt = Db::pdo()->prepare("
            UPDATE users
            SET xp = xp + :delta
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['delta' => $delta, 'id' => $userId]);
    }

    public function addXpEvent(int $userId, string $type, int $delta, array $meta = []): void
    {
        $stmt = Db::pdo()->prepare("
            INSERT INTO xp_events (user_id, type, delta, meta)
            VALUES (:uid, :type, :delta, :meta)
        ");
        $stmt->execute([
            'uid' => $userId,
            'type' => $type,
            'delta' => $delta,
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * Check existence of an xp event for a given meta key/value.
     * Example: hasEventMeta($uid, 'ANALYSIS_ADDED', 'userBookId', 12)
     */
    public function hasEventMeta(int $userId, string $type, string $metaKey, string|int $metaValue): bool
    {
        $sql = "
            SELECT 1
            FROM xp_events
            WHERE user_id = :uid
              AND type = :type
              AND JSON_UNQUOTE(JSON_EXTRACT(meta, :path)) = :val
            LIMIT 1
        ";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([
            'uid' => $userId,
            'type' => $type,
            'path' => '$.' . $metaKey,
            'val' => (string)$metaValue,
        ]);

        return (bool)$stmt->fetchColumn();
    }
}