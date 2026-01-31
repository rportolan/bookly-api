<?php
namespace App\Repositories;

use App\Core\Db;

final class BadgeRepository
{
    public function unlock(int $userId, string $badgeKey): void
    {
        $pdo = Db::pdo();

        // INSERT IGNORE pour éviter doublon (uq_user_badge)
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO user_badges (user_id, badge_key)
            VALUES (:user_id, :badge_key)
        ");

        $stmt->execute([
            'user_id' => $userId,
            'badge_key' => $badgeKey,
        ]);
    }

    public function listKeys(int $userId): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT badge_key
            FROM user_badges
            WHERE user_id = :user_id
            ORDER BY unlocked_at ASC
        ");
        $stmt->execute(['user_id' => $userId]);

        $rows = $stmt->fetchAll();
        if (!$rows) return [];

        return array_map(fn($r) => (string)$r['badge_key'], $rows);
    }
}
