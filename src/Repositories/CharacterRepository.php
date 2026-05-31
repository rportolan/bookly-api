<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class CharacterRepository
{
    public function listForBook(int $userId, int $userBookId): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.role, c.description, c.created_at
            FROM characters c
            JOIN user_books ub ON ub.id = c.user_book_id
            WHERE c.user_book_id = :user_book_id
              AND ub.user_id = :user_id
            ORDER BY c.created_at ASC
        ");
        $stmt->execute(['user_book_id' => $userBookId, 'user_id' => $userId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function create(int $userId, int $userBookId, string $name, ?string $role, ?string $description): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            INSERT INTO characters (user_book_id, name, role, description)
            SELECT ub.id, :name, :role, :description
            FROM user_books ub
            WHERE ub.id = :user_book_id
              AND ub.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            'user_book_id' => $userBookId,
            'user_id'      => $userId,
            'name'         => $name,
            'role'         => $role,
            'description'  => $description,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Not Found');
        }

        $id = (int)$pdo->lastInsertId();

        $row = $pdo->prepare("SELECT id, name, role, description, created_at FROM characters WHERE id = :id LIMIT 1");
        $row->execute(['id' => $id]);

        return $row->fetch(\PDO::FETCH_ASSOC);
    }

    public function update(int $userId, int $userBookId, int $characterId, string $name, ?string $role, ?string $description): ?array
    {
        $pdo = Db::pdo();

        $check = $pdo->prepare("
            SELECT c.id
            FROM characters c
            JOIN user_books ub ON ub.id = c.user_book_id
            WHERE c.id = :character_id
              AND c.user_book_id = :user_book_id
              AND ub.user_id = :user_id
            LIMIT 1
        ");
        $check->execute([
            'character_id' => $characterId,
            'user_book_id' => $userBookId,
            'user_id'      => $userId,
        ]);

        if (!$check->fetch()) return null;

        $upd = $pdo->prepare("
            UPDATE characters
            SET name = :name, role = :role, description = :description
            WHERE id = :character_id
        ");
        $upd->execute([
            'name'         => $name,
            'role'         => $role,
            'description'  => $description,
            'character_id' => $characterId,
        ]);

        $row = $pdo->prepare("SELECT id, name, role, description, created_at FROM characters WHERE id = :id LIMIT 1");
        $row->execute(['id' => $characterId]);

        return $row->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function delete(int $userId, int $userBookId, int $characterId): bool
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            DELETE c
            FROM characters c
            JOIN user_books ub ON ub.id = c.user_book_id
            WHERE c.id = :character_id
              AND c.user_book_id = :user_book_id
              AND ub.user_id = :user_id
        ");
        $stmt->execute([
            'character_id' => $characterId,
            'user_book_id' => $userBookId,
            'user_id'      => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
