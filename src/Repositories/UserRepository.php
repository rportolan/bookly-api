<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class UserRepository
{
    public function findByEmail(string $email): ?array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => trim($email)]);
        $u = $stmt->fetch();
        return $u ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
        $stmt->execute(['username' => trim($username)]);
        $u = $stmt->fetch();
        return $u ?: null;
    }

    public function findById(int $id): ?array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $u = $stmt->fetch();
        return $u ?: null;
    }

    public function findByGoogleId(string $googleId): ?array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = :gid LIMIT 1");
        $stmt->execute(['gid' => $googleId]);
        $u = $stmt->fetch();
        return $u ?: null;
    }

    public function create(array $data): int
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            INSERT INTO users (
                email, username, password_hash,
                google_id, avatar_url,
                email_verified_at,
                first_name, last_name, bio,
                reading_goal, preferred_genres, onboarding_completed,
                goal_pages_per_day, language, density,
                xp
            )
            VALUES (
                :email, :username, :password_hash,
                :google_id, :avatar_url,
                :email_verified_at,
                :first_name, :last_name, :bio,
                :reading_goal, :preferred_genres, :onboarding_completed,
                :goal, :lang, :density,
                :xp
            )
        ");

        $stmt->execute([
            'email'                => (string)($data['email'] ?? ''),
            'username'             => $data['username'] ?? null,
            'password_hash'        => $data['password_hash'] ?? null,
            'google_id'            => $data['google_id'] ?? null,
            'avatar_url'           => $data['avatar_url'] ?? null,
            'email_verified_at'    => $data['email_verified_at'] ?? null,
            'first_name'           => (string)($data['first_name'] ?? ''),
            'last_name'            => (string)($data['last_name'] ?? ''),
            'bio'                  => $data['bio'] ?? null,
            'reading_goal'         => $data['reading_goal'] ?? null,
            'preferred_genres'     => $data['preferred_genres'] ?? null,
            'onboarding_completed' => (int)($data['onboarding_completed'] ?? 0),
            'goal'                 => (int)($data['goal_pages_per_day'] ?? 20),
            'lang'                 => (string)($data['language'] ?? 'FR'),
            'density'              => (string)($data['density'] ?? 'Comfort'),
            'xp'                   => (int)($data['xp'] ?? 0),
        ]);

        return (int)$pdo->lastInsertId();
    }

    public function markEmailVerified(int $userId): void
    {
        Db::pdo()->prepare("
            UPDATE users SET email_verified_at = NOW() WHERE id = :id LIMIT 1
        ")->execute(['id' => $userId]);
    }

    public function linkGoogleId(int $userId, string $googleId, ?string $avatarUrl): void
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("
            UPDATE users
            SET google_id = :gid,
                avatar_url = COALESCE(:avatar, avatar_url),
                email_verified_at = COALESCE(email_verified_at, NOW())
            WHERE id = :id LIMIT 1
        ");
        $stmt->execute(['gid' => $googleId, 'avatar' => $avatarUrl, 'id' => $userId]);
    }

    public function saveOnboarding(
        int $userId,
        string $firstName,
        string $readingGoal,
        array $genres
    ): void {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("
            UPDATE users
            SET first_name            = :first_name,
                reading_goal          = :reading_goal,
                preferred_genres      = :preferred_genres,
                onboarding_completed  = 1
            WHERE id = :id LIMIT 1
        ");
        $stmt->execute([
            'first_name'       => $firstName,
            'reading_goal'     => $readingGoal,
            'preferred_genres' => json_encode($genres, JSON_UNESCAPED_UNICODE),
            'id'               => $userId,
        ]);
    }

    /**
     * Update partiel : seuls les champs présents dans $fields sont modifiés.
     */
    public function updateById(int $id, array $fields): void
    {
        $pdo = Db::pdo();

        $map = [
            'first_name'           => 'first_name',
            'last_name'            => 'last_name',
            'username'             => 'username',
            'bio'                  => 'bio',
            'goal_pages_per_day'   => 'goal_pages_per_day',
            'language'             => 'language',
            'density'              => 'density',
            'reading_goal'         => 'reading_goal',
            'preferred_genres'     => 'preferred_genres',
            'onboarding_completed' => 'onboarding_completed',
        ];

        $set    = [];
        $params = ['id' => $id];

        foreach ($map as $key => $col) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $val = $fields[$key];
            if ($val === null && !in_array($key, ['bio', 'reading_goal', 'preferred_genres'], true)) {
                continue;
            }

            $set[]        = "{$col} = :{$key}";
            $params[$key] = $val;
        }

        if (empty($set)) {
            return;
        }

        $sql  = "UPDATE users SET " . implode(', ', $set) . " WHERE id = :id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        Db::pdo()->prepare("
            UPDATE users SET password_hash = :hash WHERE id = :id LIMIT 1
        ")->execute(['hash' => $hash, 'id' => $id]);
    }

    public function deleteById(int $id): void
    {
        Db::pdo()->prepare("DELETE FROM users WHERE id = :id LIMIT 1")
            ->execute(['id' => $id]);
    }
}
