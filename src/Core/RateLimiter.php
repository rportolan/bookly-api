<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Rate limiter simple basé sur MySQL — aucune dépendance externe.
 *
 * Utilise la table `rate_limit_hits` (à créer via la migration fournie).
 * Stratégie : fenêtre glissante. Si l'IP dépasse $maxHits sur $windowSeconds,
 * on lève une HttpException 429.
 */
final class RateLimiter
{
    /**
     * Vérifie + incrémente le compteur pour une clé donnée.
     *
     * @param string $key          Clé unique ex: "login:192.168.1.1"
     * @param int    $maxHits      Nombre max de requêtes autorisées
     * @param int    $windowSeconds Fenêtre en secondes
     */
    public static function check(string $key, int $maxHits, int $windowSeconds): void
    {
        $pdo = Db::pdo();
        $now = time();
        $windowStart = date('Y-m-d H:i:s', $now - $windowSeconds);

        // Nettoyage des vieilles entrées (1 chance sur 20 pour ne pas le faire à chaque requête)
        if (mt_rand(1, 20) === 1) {
            $pdo->prepare("DELETE FROM rate_limit_hits WHERE window_start < :old")
                ->execute(['old' => date('Y-m-d H:i:s', $now - $windowSeconds * 2)]);
        }

        // Upsert : on incrémente ou on crée l'entrée
        $pdo->prepare("
            INSERT INTO rate_limit_hits (hit_key, hits, window_start)
            VALUES (:key, 1, :window_start)
            ON DUPLICATE KEY UPDATE
                hits = IF(window_start < :window_start2, 1, hits + 1),
                window_start = IF(window_start < :window_start3, :window_start4, window_start)
        ")->execute([
            'key'           => $key,
            'window_start'  => date('Y-m-d H:i:s', $now),
            'window_start2' => $windowStart,
            'window_start3' => $windowStart,
            'window_start4' => date('Y-m-d H:i:s', $now),
        ]);

        // Lecture du compteur actuel
        $stmt = $pdo->prepare("SELECT hits FROM rate_limit_hits WHERE hit_key = :key LIMIT 1");
        $stmt->execute(['key' => $key]);
        $hits = (int)($stmt->fetchColumn() ?: 0);

        if ($hits > $maxHits) {
            throw new HttpException(
                429,
                'TOO_MANY_REQUESTS',
                ['retryAfter' => $windowSeconds],
                'Too many requests, please slow down'
            );
        }
    }

    /**
     * Récupère l'IP du client de façon fiable.
     * Sur Railway/proxy, utilise X-Forwarded-For si disponible.
     */
    public static function ip(): string
    {
        // Railway et la plupart des proxies mettent l'IP réelle ici
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            // Peut contenir plusieurs IPs séparées par virgule, on prend la première
            $ip = trim(explode(',', $forwarded)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}