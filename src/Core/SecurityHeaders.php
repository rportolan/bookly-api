<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Headers de sécurité HTTP — à appeler une fois dans index.php,
 * juste après les headers CORS existants.
 */
final class SecurityHeaders
{
    public static function send(): void
    {
        // Empêche le clickjacking (intégration dans une iframe)
        header('X-Frame-Options: DENY');

        // Empêche le MIME-sniffing (le navigateur ne devine pas le type de fichier)
        header('X-Content-Type-Options: nosniff');

        // Ne pas envoyer le referrer vers des sites tiers
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Désactive les fonctionnalités navigateur non utilisées
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        // Force HTTPS (seulement si on est en prod/HTTPS)
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); // Railway utilise ce header

        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}