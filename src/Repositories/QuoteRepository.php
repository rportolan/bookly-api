<?php
namespace App\Repositories;

use App\Core\Db;

final class QuoteRepository
{
    public function listForBook(int $userId, int $userBookId): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT q.id, q.content, q.author, q.created_at
            FROM quotes q
            JOIN user_books ub ON ub.id = q.user_book_id
            WHERE ub.id = :user_book_id
              AND ub.user_id = :user_id
            ORDER BY q.created_at DESC
        ");

        $stmt->execute([
            'user_book_id' => $userBookId,
            'user_id' => $userId,
        ]);

        return $stmt->fetchAll();
    }

    public function create(int $userId, int $userBookId, string $content, ?string $author): array
    {
        $pdo = Db::pdo();

        // Insert only if the user owns the user_book
        $stmt = $pdo->prepare("
            INSERT INTO quotes (user_book_id, content, author)
            SELECT ub.id, :content, :author
            FROM user_books ub
            WHERE ub.id = :user_book_id
              AND ub.user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            'user_book_id' => $userBookId,
            'user_id' => $userId,
            'content' => $content,
            'author' => $author,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException("Not Found");
        }

        $id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT id, content, author, created_at FROM quotes WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    public function delete(int $userId, int $userBookId, int $quoteId): bool
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            DELETE q
            FROM quotes q
            JOIN user_books ub ON ub.id = q.user_book_id
            WHERE q.id = :quote_id
              AND ub.id = :user_book_id
              AND ub.user_id = :user_id
        ");

        $stmt->execute([
            'quote_id' => $quoteId,
            'user_book_id' => $userBookId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function update(int $userId, int $userBookId, int $quoteId, string $content, ?string $author): ?array
    {
        $pdo = Db::pdo();

        // 1) Check ownership + relation quote -> user_book
        $check = $pdo->prepare("
            SELECT q.id
            FROM quotes q
            JOIN user_books ub ON ub.id = q.user_book_id
            WHERE q.id = :quote_id
              AND ub.id = :user_book_id
              AND ub.user_id = :user_id
            LIMIT 1
        ");
        $check->execute([
            'quote_id' => $quoteId,
            'user_book_id' => $userBookId,
            'user_id' => $userId,
        ]);

        if (!$check->fetch()) return null;

        // 2) Update
        $upd = $pdo->prepare("
            UPDATE quotes
            SET content = :content,
                author = :author
            WHERE id = :quote_id
        ");
        $upd->execute([
            'content' => $content,
            'author' => $author,
            'quote_id' => $quoteId,
        ]);

        // 3) Return row
        $stmt2 = $pdo->prepare("SELECT id, content, author, created_at FROM quotes WHERE id = :id LIMIT 1");
        $stmt2->execute(['id' => $quoteId]);

        return $stmt2->fetch() ?: null;
    }
}