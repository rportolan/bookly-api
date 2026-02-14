<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class UserRepository
{
    public function findByEmail(string $email): ?array
    {
        $email = trim($email);

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);

        $u = $stmt->fetch();
        return $u ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $username = trim($username);

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => $username]);

        $u = $stmt->fetch();
        return $u ?: null;
    }

    public function findById(int $id): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);

        $u = $stmt->fetch();
        return $u ?: null;
    }

    public function create(array $data): int
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            INSERT INTO users (
                email, username, password_hash,
                email_verified_at,
                first_name, last_name, bio,
                goal_pages_per_day, language, density,
                xp
            )
            VALUES (
                :email, :username, :password_hash,
                :email_verified_at,
                :first_name, :last_name, :bio,
                :goal, :lang, :density,
                :xp
            )
        ");

        $stmt->execute([
            'email' => (string)($data['email'] ?? ''),
            'username' => (string)($data['username'] ?? ''),
            'password_hash' => (string)($data['password_hash'] ?? ''),
            'email_verified_at' => $data['email_verified_at'] ?? null,
            'first_name' => (string)($data['first_name'] ?? ''),
            'last_name' => (string)($data['last_name'] ?? ''),
            'bio' => $data['bio'] ?? null,
            'goal' => (int)($data['goal_pages_per_day'] ?? 20),
            'lang' => (string)($data['language'] ?? 'FR'),
            'density' => (string)($data['density'] ?? 'Comfort'),
            'xp' => (int)($data['xp'] ?? 0),
        ]);

        return (int)$pdo->lastInsertId();
    }

    public function markEmailVerified(int $userId): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            UPDATE users
            SET email_verified_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Update partiel: seuls les champs non-null sont modifiés.
     */
    public function updateById(int $id, array $fields): void
    {
        $pdo = Db::pdo();

        $map = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'username' => 'username',
            'bio' => 'bio',
            'goal_pages_per_day' => 'goal_pages_per_day',
            'language' => 'language',
            'density' => 'density',
        ];

        $set = [];
        $params = ['id' => $id];

        foreach ($map as $key => $col) {
            if (!array_key_exists($key, $fields)) continue;

            $val = $fields[$key];
            if ($val === null && $key !== 'bio') {
                continue;
            }

            $set[] = "{$col} = :{$key}";
            $params[$key] = $val;
        }

        if (empty($set)) return;

        $sql = "UPDATE users SET " . implode(', ', $set) . " WHERE id = :id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            UPDATE users
            SET password_hash = :hash
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'hash' => $hash,
            'id' => $id,
        ]);
    }

    public function deleteById(int $id): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
    }

   

}
