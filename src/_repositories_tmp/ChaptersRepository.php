<?php
namespace App\Repositories;

use App\Core\Db;

final class ChaptersRepository
{
    public function listForBook(int $userId, int $userBookId): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT c.id, c.title, c.resume, c.observation, c.position, c.created_at, c.updated_at
            FROM chapters c
            JOIN user_books ub ON ub.id = c.user_book_id
            WHERE ub.id = :user_book_id
              AND ub.user_id = :user_id
            ORDER BY
              CASE WHEN c.position IS NULL THEN 1 ELSE 0 END,
              c.position ASC,
              c.created_at DESC
        ");

        $stmt->execute([
            'user_book_id' => $userBookId,
            'user_id' => $userId,
        ]);

        return $stmt->fetchAll();
    }

    public function create(
        int $userId,
        int $userBookId,
        string $title,
        ?string $resume,
        ?string $observation,
        ?int $position
    ): array {
        $pdo = Db::pdo();

        // Insert only if the user owns the user_book
        $stmt = $pdo->prepare("
            INSERT INTO chapters (user_book_id, title, resume, observation, position)
            SELECT ub.id, :title, :resume, :observation, :position
            FROM user_books ub
            WHERE ub.id = :user_book_id
              AND ub.user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            'user_book_id' => $userBookId,
            'user_id' => $userId,
            'title' => $title,
            'resume' => $resume,
            'observation' => $observation,
            'position' => $position,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException("Not Found");
        }

        $id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT id, title, resume, observation, position, created_at, updated_at
            FROM chapters
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    public function update(int $userId, int $userBookId, int $chapterId, array $patch): ?array
    {
        $pdo = Db::pdo();

        // Vérifie ownership + appartenance chapitre -> user_book
        $stmt = $pdo->prepare("
            SELECT c.id
            FROM chapters c
            JOIN user_books ub ON ub.id = c.user_book_id
            WHERE c.id = :chapter_id
              AND ub.id = :user_book_id
              AND ub.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            'chapter_id' => $chapterId,
            'user_book_id' => $userBookId,
            'user_id' => $userId,
        ]);

        if (!$stmt->fetch()) return null;

        $fields = [];
        $params = [
            'chapter_id' => $chapterId,
        ];

        foreach (['title', 'resume', 'observation', 'position'] as $k) {
            if (array_key_exists($k, $patch)) {
                $fields[] = "c.$k = :$k";
                $params[$k] = $patch[$k];
            }
        }

        if ($fields) {
            $sql = "UPDATE chapters c SET " . implode(", ", $fields) . " WHERE c.id = :chapter_id";
            $upd = $pdo->prepare($sql);
            $upd->execute($params);
        }

        $stmt = $pdo->prepare("
            SELECT id, title, resume, observation, position, created_at, updated_at
            FROM chapters
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $chapterId]);

        return $stmt->fetch() ?: null;
    }

    public function delete(int $userId, int $userBookId, int $chapterId): bool
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            DELETE c
            FROM chapters c
            JOIN user_books ub ON ub.id = c.user_book_id
            WHERE c.id = :chapter_id
              AND ub.id = :user_book_id
              AND ub.user_id = :user_id
        ");

        $stmt->execute([
            'chapter_id' => $chapterId,
            'user_book_id' => $userBookId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
