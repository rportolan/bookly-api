<?php
namespace App\Repositories;

use App\Core\Db;

final class QuestRepository
{
    public function statsForUser(int $userId): array
    {
        $pdo = Db::pdo();

        // total / done / reading / pagesRead depuis user_books
        $stmt = $pdo->prepare("
            SELECT
              COUNT(*) AS total,
              SUM(CASE WHEN status = 'Terminé' THEN 1 ELSE 0 END) AS done,
              SUM(CASE WHEN status = 'En cours' THEN 1 ELSE 0 END) AS reading,
              COALESCE(SUM(progress_pages), 0) AS pagesRead
            FROM user_books
            WHERE user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch() ?: [];

        // vocab count
        $stmt = $pdo->prepare("
            SELECT COALESCE(COUNT(v.id), 0) AS vocab
            FROM vocab v
            JOIN user_books ub ON ub.id = v.user_book_id
            WHERE ub.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $vRow = $stmt->fetch() ?: [];

        // quotes count
        $stmt = $pdo->prepare("
            SELECT COALESCE(COUNT(q.id), 0) AS quotes
            FROM quotes q
            JOIN user_books ub ON ub.id = q.user_book_id
            WHERE ub.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $qRow = $stmt->fetch() ?: [];

        // chapters count
        $stmt = $pdo->prepare("
            SELECT COALESCE(COUNT(c.id), 0) AS chapters
            FROM chapters c
            JOIN user_books ub ON ub.id = c.user_book_id
            WHERE ub.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $cRow = $stmt->fetch() ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'done' => (int)($row['done'] ?? 0),
            'reading' => (int)($row['reading'] ?? 0),
            'pagesRead' => (int)($row['pagesRead'] ?? 0),
            'vocab' => (int)($vRow['vocab'] ?? 0),
            'quotes' => (int)($qRow['quotes'] ?? 0),
            'chapters' => (int)($cRow['chapters'] ?? 0),
        ];
    }
}
