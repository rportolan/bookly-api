<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db; // adapte si ton projet a un autre helper DB

final class PasswordResetRepository
{
    public function upsertTokenForUser(int $userId, string $tokenHash, int $ttlSeconds): void
    {
        $sql = "
          INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at, sent_at, used_at)
          VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW(), NOW(), NULL)
          ON DUPLICATE KEY UPDATE
            token_hash = VALUES(token_hash),
            expires_at = VALUES(expires_at),
            sent_at = NOW(),
            used_at = NULL
        ";

        Db::pdo()->prepare($sql)->execute([
            ':uid' => $userId,
            ':hash' => $tokenHash,
            ':ttl' => $ttlSeconds,
        ]);
    }

    public function findValidByEmailAndTokenHash(string $email, string $tokenHash): ?array
    {
        $sql = "
          SELECT prt.*, u.email
          FROM password_reset_tokens prt
          JOIN users u ON u.id = prt.user_id
          WHERE u.email = :email AND prt.token_hash = :hash
          LIMIT 1
        ";
        $st = Db::pdo()->prepare($sql);
        $st->execute([':email' => $email, ':hash' => $tokenHash]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markUsed(int $userId): void
    {
        $sql = "UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = :uid LIMIT 1";
        Db::pdo()->prepare($sql)->execute([':uid' => $userId]);
    }

    public function canResend(int $userId, int $cooldownSeconds): bool
    {
        $sql = "SELECT sent_at FROM password_reset_tokens WHERE user_id = :uid LIMIT 1";
        $st = Db::pdo()->prepare($sql);
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['sent_at'])) return true;

        $sentAt = strtotime((string)$row['sent_at']);
        if ($sentAt <= 0) return true;

        return (time() - $sentAt) >= $cooldownSeconds;
    }
}
