<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class AnalysisRepository
{
    public function get(int $userId, int $userBookId): ?array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT ba.id, ba.content, ba.updated_at
            FROM book_analyses ba
            JOIN user_books ub ON ub.id = ba.user_book_id
            WHERE ba.user_book_id = :user_book_id
              AND ub.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_book_id' => $userBookId, 'user_id' => $userId]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsert(int $userId, int $userBookId, string $content): array
    {
        $pdo = Db::pdo();

        // Verify ownership first
        $check = $pdo->prepare("
            SELECT id FROM user_books
            WHERE id = :user_book_id AND user_id = :user_id
            LIMIT 1
        ");
        $check->execute(['user_book_id' => $userBookId, 'user_id' => $userId]);

        if (!$check->fetch()) {
            throw new \RuntimeException('Not Found');
        }

        $stmt = $pdo->prepare("
            INSERT INTO book_analyses (user_book_id, content)
            VALUES (:user_book_id, :content)
            ON DUPLICATE KEY UPDATE content = :content2
        ");
        $stmt->execute(['user_book_id' => $userBookId, 'content' => $content, 'content2' => $content]);

        $row = $this->get($userId, $userBookId);
        return $row ?? ['content' => $content, 'updated_at' => null];
    }
}
