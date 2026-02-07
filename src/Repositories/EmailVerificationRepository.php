<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class EmailVerificationRepository
{
    public function upsertTokenForUser(int $userId, string $tokenHash, int $ttlSeconds): void
    {
        $pdo = Db::pdo();

        // 1 token actif par user (UNIQUE user_id)
        $stmt = $pdo->prepare("
            INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, sent_at)
            VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW())
            ON DUPLICATE KEY UPDATE
                token_hash = VALUES(token_hash),
                expires_at = VALUES(expires_at),
                sent_at = NOW(),
                used_at = NULL
        ");

        $stmt->execute([
            'uid' => $userId,
            'hash' => $tokenHash,
            'ttl' => $ttlSeconds,
        ]);
    }

    public function markUsed(int $userId): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            UPDATE email_verification_tokens
            SET used_at = NOW()
            WHERE user_id = :uid
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
    }

    public function findValidByEmailAndTokenHash(string $email, string $tokenHash): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT evt.user_id, evt.expires_at, evt.used_at, u.email
            FROM email_verification_tokens evt
            INNER JOIN users u ON u.id = evt.user_id
            WHERE u.email = :email
              AND evt.token_hash = :hash
            LIMIT 1
        ");
        $stmt->execute([
            'email' => $email,
            'hash' => $tokenHash,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function canResend(int $userId, int $cooldownSeconds = 60): bool
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT sent_at
            FROM email_verification_tokens
            WHERE user_id = :uid
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();

        if (!$row || empty($row['sent_at'])) {
            return true;
        }

        $sentAt = strtotime((string)$row['sent_at']);
        if ($sentAt <= 0) return true;

        return (time() - $sentAt) >= $cooldownSeconds;
    }
}
