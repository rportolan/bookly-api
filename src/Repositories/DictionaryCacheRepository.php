<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class DictionaryCacheRepository
{
    public function getFresh(string $lang, string $term): ?array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT id, lang, term, data, fetched_at, expires_at
            FROM dictionary_cache
            WHERE lang = :lang
              AND term = :term
              AND expires_at > NOW()
            LIMIT 1
        ");

        $stmt->execute(['lang' => $lang, 'term' => $term]);

        $row = $stmt->fetch();
        if (!$row) return null;

        $decoded = json_decode((string)$row['data'], true);
        if (!is_array($decoded)) return null;

        return $decoded;
    }

    public function upsert(string $lang, string $term, array $data, int $ttlDays): void
    {
        $pdo     = Db::pdo();
        $ttlDays = max(1, min(365, $ttlDays));
        $json    = json_encode($data, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            INSERT INTO dictionary_cache (lang, term, data, fetched_at, expires_at)
            VALUES (:lang, :term, :data, NOW(), DATE_ADD(NOW(), INTERVAL :ttl DAY))
            ON DUPLICATE KEY UPDATE
                data       = VALUES(data),
                fetched_at = NOW(),
                expires_at = DATE_ADD(NOW(), INTERVAL :ttl DAY)
        ");

        $stmt->execute([
            'lang' => $lang,
            'term' => $term,
            'data' => $json,
            'ttl'  => $ttlDays,
        ]);
    }
}
