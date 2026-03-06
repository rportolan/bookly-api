<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class CardRepository
{
    public function all(): array
    {
        $stmt = Db::pdo()->query("
            SELECT *
            FROM cards
            ORDER BY id ASC
        ");

        return $stmt->fetchAll() ?: [];
    }

    public function userHas(int $userId, int $cardId): bool
    {
        $stmt = Db::pdo()->prepare("
            SELECT 1
            FROM user_cards
            WHERE user_id = :uid
              AND card_id = :cid
            LIMIT 1
        ");

        $stmt->execute(['uid' => $userId, 'cid' => $cardId]);

        return (bool)$stmt->fetch();
    }

    public function unlock(int $userId, int $cardId): void
    {
        $stmt = Db::pdo()->prepare("
            INSERT IGNORE INTO user_cards (user_id, card_id, status, unlocked_at)
            VALUES (:uid, :cid, 'unclaimed', NOW())
        ");

        $stmt->execute(['uid' => $userId, 'cid' => $cardId]);
    }

    public function claim(int $userId, int $cardId): void
    {
        $stmt = Db::pdo()->prepare("
            UPDATE user_cards
            SET status = 'claimed',
                claimed_at = NOW()
            WHERE user_id = :uid
              AND card_id = :cid
              AND status = 'unclaimed'
        ");

        $stmt->execute(['uid' => $userId, 'cid' => $cardId]);
    }

    public function findForUser(int $userId, int $cardId): ?array
    {
        $stmt = Db::pdo()->prepare("
            SELECT 
                c.*,
                uc.status,
                uc.unlocked_at,
                uc.claimed_at
            FROM cards c
            LEFT JOIN user_cards uc
              ON uc.card_id = c.id
             AND uc.user_id = :uid
            WHERE c.id = :cid
            LIMIT 1
        ");

        $stmt->execute(['uid' => $userId, 'cid' => $cardId]);

        $row = $stmt->fetch();
        if (!$row) return null;

        return $this->mapRow($row);
    }

    public function listForUser(int $userId): array
    {
        $stmt = Db::pdo()->prepare("
            SELECT 
                c.*,
                uc.status,
                uc.unlocked_at,
                uc.claimed_at
            FROM cards c
            LEFT JOIN user_cards uc
              ON uc.card_id = c.id
             AND uc.user_id = :uid
            ORDER BY c.id ASC
        ");

        $stmt->execute(['uid' => $userId]);

        $rows = $stmt->fetchAll() ?: [];
        return array_map(fn($r) => $this->mapRow($r), $rows);
    }

    private function mapRow(array $row): array
    {
        $status = $row['status'] ?? null; // null => locked

        return [
            'id' => (int)$row['id'],
            'code' => (string)($row['code'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'subtitle' => $row['subtitle'] ?? null,
            'description' => $row['description'] ?? null,
            'type' => $row['type'] ?? null,
            'rarity' => (string)($row['rarity'] ?? 'common'),
            'series' => $row['series'] ?? null,
            'image_url' => $row['image_url'] ?? null,

            // ✅ AJOUT : infos de déblocage (viennent de cards.*)
            'unlock_type' => (string)($row['unlock_type'] ?? ''),
            'unlock_value' => (int)($row['unlock_value'] ?? 0),

            // état user
            'status' => $status ?? 'locked',
            'unlocked' => $status !== null,
            'claimed' => $status === 'claimed',
            'unlocked_at' => $row['unlocked_at'] ?? null,
            'claimed_at' => $row['claimed_at'] ?? null,
        ];
    }
}