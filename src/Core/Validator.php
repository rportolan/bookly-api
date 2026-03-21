<?php
declare(strict_types=1);

namespace App\Core;

final class Validator
{
    public static function requireString(array $body, string $key, int $min = 1, int $max = 255): string
    {
        $val = trim((string)($body[$key] ?? ''));
        if ($val === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => $key], "$key is required");
        }
        $len = mb_strlen($val);
        if ($len < $min || $len > $max) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => $key, 'min' => $min, 'max' => $max], "$key length invalid");
        }
        return $val;
    }

    public static function optionalInt(array $body, string $key, int $min = 0, int $max = PHP_INT_MAX): ?int
    {
        if (!array_key_exists($key, $body)) return null;
        if ($body[$key] === null) return null;
        if (!is_numeric($body[$key])) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => $key], "$key must be a number");
        }
        $val = (int)$body[$key];
        if ($val < $min || $val > $max) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => $key, 'min' => $min, 'max' => $max], "$key out of range");
        }
        return $val;
    }

    /**
     * Valide qu'un champ texte optionnel ne dépasse pas une taille max.
     * Utilisé pour les grands champs (summary, analysis_work, resume, observation...).
     *
     * @param int $maxChars Par défaut 20 000 caractères (~20 ko de texte)
     */
    public static function optionalText(array $body, string $key, int $maxChars = 20000): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }

        $val = (string)$body[$key];

        if (mb_strlen($val) > $maxChars) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => $key, 'max' => $maxChars],
                "$key is too long (max $maxChars characters)"
            );
        }

        return $val === '' ? null : $val;
    }
}