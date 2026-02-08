<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class GoogleBooksCacheRepository
{
    public function getValid(string $cacheKey): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT id, cache_key, query, response_json, expires_at
            FROM google_books_cache
            WHERE cache_key = :k AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute(['k' => $cacheKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function upsert(string $cacheKey, string $query, string $responseJson, int $ttlSeconds): void
    {
        $pdo = Db::pdo();

        $ttlSeconds = max(60, min(60 * 60 * 24 * 30, $ttlSeconds)); // 1 min -> 30 jours
        $expiresAt = (new \DateTimeImmutable('now'))
            ->modify('+' . $ttlSeconds . ' seconds')
            ->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO google_books_cache (cache_key, query, response_json, expires_at)
            VALUES (:k, :q, :j, :exp)
            ON DUPLICATE KEY UPDATE
              query = VALUES(query),
              response_json = VALUES(response_json),
              expires_at = VALUES(expires_at),
              updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            'k' => $cacheKey,
            'q' => mb_substr($query, 0, 255),
            'j' => $responseJson,
            'exp' => $expiresAt,
        ]);
    }

    public function purgeExpired(int $limit = 500): int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            DELETE FROM google_books_cache
            WHERE expires_at <= NOW()
            LIMIT :lim
        ");
        $stmt->bindValue(':lim', max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
