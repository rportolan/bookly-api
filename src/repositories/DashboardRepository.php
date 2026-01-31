<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class DashboardRepository
{
    public function lastReadBook(int $userId): ?array
    {
        $pdo = Db::pdo();

        // "Dernier livre lu" = dernier user_book mis à jour (progress/status/etc.)
        // Tu peux affiner : WHERE ub.progress_pages > 0 si tu veux ignorer ceux à 0.
        $stmt = $pdo->prepare("
            SELECT
              ub.id              AS userBookId,
              ub.status          AS status,
              ub.progress_pages  AS progressPages,
              ub.rating          AS rating,
              ub.started_at      AS startedAt,
              ub.finished_at     AS finishedAt,
              ub.updated_at      AS updatedAt,

              b.id               AS bookId,
              b.title            AS title,
              b.author           AS author,
              b.genre            AS genre,
              b.pages            AS pages,
              b.publisher        AS publisher,
              b.cover_url        AS coverUrl
            FROM user_books ub
            JOIN books b ON b.id = ub.book_id
            WHERE ub.user_id = :uid
            ORDER BY ub.updated_at DESC
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) return null;

        $pages = (int)($row['pages'] ?? 0);
        $progress = (int)($row['progressPages'] ?? 0);
        $pct = $pages > 0 ? round(($progress / $pages) * 100) : 0;

        return [
            'userBookId'     => (int)$row['userBookId'],
            'bookId'         => (int)$row['bookId'],
            'title'          => (string)$row['title'],
            'author'         => (string)$row['author'],
            'genre'          => $row['genre'] !== null ? (string)$row['genre'] : null,
            'pages'          => $pages,
            'publisher'      => $row['publisher'] !== null ? (string)$row['publisher'] : null,
            'coverUrl'       => $row['coverUrl'] !== null ? (string)$row['coverUrl'] : null,

            'status'         => (string)$row['status'],
            'progressPages'  => $progress,
            'rating'         => $row['rating'] !== null ? (float)$row['rating'] : null,
            'startedAt'      => $row['startedAt'],
            'finishedAt'     => $row['finishedAt'],
            'updatedAt'      => $row['updatedAt'],

            'progressPct'    => (int)$pct,
        ];
    }
}
