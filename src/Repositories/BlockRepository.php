<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class BlockRepository
{
    public function listForBook(int $userId, int $userBookId): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT ubb.type, ubb.sort_order
            FROM user_book_blocks ubb
            JOIN user_books ub ON ub.id = ubb.user_book_id
            WHERE ubb.user_book_id = :user_book_id
              AND ub.user_id = :user_id
            ORDER BY ubb.sort_order ASC, ubb.created_at ASC
        ");
        $stmt->execute(['user_book_id' => $userBookId, 'user_id' => $userId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function add(int $userId, int $userBookId, string $type): array
    {
        $pdo = Db::pdo();

        // Compute next sort_order
        $ord = $pdo->prepare("
            SELECT COALESCE(MAX(ubb.sort_order), -1) + 1
            FROM user_book_blocks ubb
            JOIN user_books ub ON ub.id = ubb.user_book_id
            WHERE ubb.user_book_id = :user_book_id
              AND ub.user_id = :user_id
        ");
        $ord->execute(['user_book_id' => $userBookId, 'user_id' => $userId]);
        $nextOrder = (int)$ord->fetchColumn();

        // Insert only if user owns the user_book (subquery ownership check)
        $stmt = $pdo->prepare("
            INSERT INTO user_book_blocks (user_book_id, type, sort_order)
            SELECT ub.id, :type, :sort_order
            FROM user_books ub
            WHERE ub.id = :user_book_id
              AND ub.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            'user_book_id' => $userBookId,
            'user_id'      => $userId,
            'type'         => $type,
            'sort_order'   => $nextOrder,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Not Found');
        }

        return ['type' => $type, 'sort_order' => $nextOrder];
    }

    public function remove(int $userId, int $userBookId, string $type): bool
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            DELETE ubb
            FROM user_book_blocks ubb
            JOIN user_books ub ON ub.id = ubb.user_book_id
            WHERE ubb.user_book_id = :user_book_id
              AND ubb.type = :type
              AND ub.user_id = :user_id
        ");
        $stmt->execute([
            'user_book_id' => $userBookId,
            'type'         => $type,
            'user_id'      => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
