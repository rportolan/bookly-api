<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class QuizPackRepository
{
    public function listByCategory(?int $categoryId = null): array
    {
        $pdo = Db::pdo();

        if ($categoryId && $categoryId > 0) {
            $stmt = $pdo->prepare("
                SELECT id, category_id, title, slug, description, cover_image_url, sort_order
                FROM quiz_packs
                WHERE category_id = :cid
                ORDER BY sort_order ASC, id ASC
            ");
            $stmt->execute(['cid' => $categoryId]);
            return $stmt->fetchAll() ?: [];
        }

        $stmt = $pdo->query("
            SELECT id, category_id, title, slug, description, cover_image_url, sort_order
            FROM quiz_packs
            ORDER BY sort_order ASC, id ASC
        ");
        return $stmt->fetchAll() ?: [];
    }

    public function find(int $packId): ?array
    {
        $stmt = Db::pdo()->prepare("
            SELECT id, category_id, title, slug, description, cover_image_url, sort_order
            FROM quiz_packs
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $packId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}