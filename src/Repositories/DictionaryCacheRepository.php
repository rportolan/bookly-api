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
            SELECT id, lang, term, definition, fetched_at, expires_at
            FROM dictionary_cache
            WHERE lang = :lang
              AND term = :term
              AND expires_at > NOW()
            LIMIT 1
        ");

        $stmt->execute([
            'lang' => $lang,
            'term' => $term,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function upsert(string $lang, string $term, string $definition, int $ttlDays): void
    {
        $pdo = Db::pdo();

        $ttlDays = max(1, min(365, $ttlDays));

        $stmt = $pdo->prepare("
            INSERT INTO dictionary_cache (lang, term, definition, fetched_at, expires_at)
            VALUES (:lang, :term, :definition, NOW(), DATE_ADD(NOW(), INTERVAL :ttl DAY))
            ON DUPLICATE KEY UPDATE
                definition = VALUES(definition),
                fetched_at = NOW(),
                expires_at = DATE_ADD(NOW(), INTERVAL :ttl DAY)
        ");

        $stmt->execute([
            'lang' => $lang,
            'term' => $term,
            'definition' => $definition,
            'ttl' => $ttlDays,
        ]);
    }
}