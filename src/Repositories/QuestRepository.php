<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class QuestRepository
{
    public function statsForUser(int $userId): array
    {
        $pdo = Db::pdo();

        // Books finished + pages read (progress pages sum)
        $stmt = $pdo->prepare("
            SELECT
              SUM(CASE WHEN ub.status = 'Terminé' THEN 1 ELSE 0 END) AS done,
              SUM(ub.progress_pages) AS pages_read,
              COUNT(*) AS books_created,
              SUM(CASE WHEN ub.analysis_work IS NOT NULL AND TRIM(ub.analysis_work) <> '' THEN 1 ELSE 0 END) AS analyses_added
            FROM user_books ub
            WHERE ub.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $books = $stmt->fetch() ?: [];

        // Quotes count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS quotes
            FROM quotes q
            JOIN user_books ub ON ub.id = q.user_book_id
            WHERE ub.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $quotes = (int)($stmt->fetchColumn() ?: 0);

        // Vocab count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS vocab
            FROM vocab v
            JOIN user_books ub ON ub.id = v.user_book_id
            WHERE ub.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $vocab = (int)($stmt->fetchColumn() ?: 0);

        // Chapters count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS chapters
            FROM chapters c
            JOIN user_books ub ON ub.id = c.user_book_id
            WHERE ub.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $chapters = (int)($stmt->fetchColumn() ?: 0);

        // Quizzes completed: count distinct quiz_id attempted
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT qa.quiz_id) AS quizzes_completed
            FROM quiz_attempts qa
            WHERE qa.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $quizzesCompleted = (int)($stmt->fetchColumn() ?: 0);

        // Streak days: computed from reading_logs
        $streakDays = (new ReadingRepository())->computeCurrentStreakDays($userId);

        return [
            'done' => (int)($books['done'] ?? 0),
            'pagesRead' => (int)($books['pages_read'] ?? 0),
            'booksCreated' => (int)($books['books_created'] ?? 0),
            'analyses' => (int)($books['analyses_added'] ?? 0),
            'quotes' => $quotes,
            'vocab' => $vocab,
            'chapters' => $chapters,
            'streakDays' => $streakDays,
            'quizzesCompleted' => $quizzesCompleted,
        ];
    }
}