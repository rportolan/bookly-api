<?php
namespace App\Repositories;

use App\Core\Db;

final class ProgressRepository
{
    public function addXpEvent(int $userId, string $type, int $delta, array $meta = []): int
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            INSERT INTO xp_events (user_id, type, delta, meta)
            VALUES (:user_id, :type, :delta, :meta)
        ");

        $stmt->execute([
            'user_id' => $userId,
            'type' => $type,
            'delta' => $delta,
            'meta' => empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int)$pdo->lastInsertId();
    }

    public function addXpToUser(int $userId, int $delta): void
    {
        $pdo = Db::pdo();

        // Evite de descendre sous 0
        $stmt = $pdo->prepare("
            UPDATE users
            SET xp = GREATEST(0, xp + :delta)
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'delta' => $delta,
            'id' => $userId,
        ]);
    }

    public function getUserXp(int $userId): int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT xp FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        return (int)($row['xp'] ?? 0);
    }
}
