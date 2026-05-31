<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class MagicLinkRepository
{
    /**
     * Supprime les tokens non-utilisés existants pour cet user
     * puis insère un nouveau token.
     */
    public function upsertForUser(int $userId, string $email, string $tokenHash, int $ttl): void
    {
        $pdo = Db::pdo();

        $pdo->prepare("
            DELETE FROM magic_link_tokens
            WHERE user_id = :uid AND used_at IS NULL
        ")->execute(['uid' => $userId]);

        $pdo->prepare("
            INSERT INTO magic_link_tokens (user_id, email, token_hash, expires_at, created_at)
            VALUES (:user_id, :email, :token_hash, DATE_ADD(NOW(), INTERVAL :ttl SECOND), NOW())
        ")->execute([
            'user_id'    => $userId,
            'email'      => $email,
            'token_hash' => $tokenHash,
            'ttl'        => $ttl,
        ]);
    }

    public function findValidByTokenHash(string $tokenHash): ?array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT * FROM magic_link_tokens
            WHERE token_hash = :hash
              AND used_at IS NULL
              AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markUsed(int $id): void
    {
        Db::pdo()->prepare("
            UPDATE magic_link_tokens SET used_at = NOW() WHERE id = :id
        ")->execute(['id' => $id]);
    }

    /**
     * Vérifie qu'aucun token n'a été créé pour cet email
     * dans les $cooldownSeconds dernières secondes.
     */
    public function canSend(string $email, int $cooldownSeconds): bool
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT id FROM magic_link_tokens
            WHERE email = :email
              AND created_at > DATE_SUB(NOW(), INTERVAL :sec SECOND)
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['email' => $email, 'sec' => $cooldownSeconds]);
        return !$stmt->fetch();
    }
}
