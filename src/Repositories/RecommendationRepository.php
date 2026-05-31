<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class RecommendationRepository
{
    public function listByMonth(string $month): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT id, month, title, author, cover_url
            FROM monthly_recommendations
            WHERE month = :month
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute(['month' => $month]);

        return array_map(fn($r) => [
            'id'       => (int)$r['id'],
            'month'    => (string)$r['month'],
            'title'    => (string)$r['title'],
            'author'   => (string)$r['author'],
            'coverUrl' => $r['cover_url'] !== null ? (string)$r['cover_url'] : null,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}
