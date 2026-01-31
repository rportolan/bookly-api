<?php
declare(strict_types=1);

namespace App\Core;

final class Jwt
{
    public static function encode(array $payload, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $segments = [
            self::b64url(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);

        $segments[] = self::b64url($signature);

        return implode('.', $segments);
    }

    public static function decode(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new HttpException(401, 'INVALID_TOKEN', [], 'Invalid token');
        }

        [$h64, $p64, $s64] = $parts;

        $header = json_decode(self::b64url_decode($h64), true);
        $payload = json_decode(self::b64url_decode($p64), true);
        $sig = self::b64url_decode($s64);

        if (!is_array($header) || !is_array($payload)) {
            throw new HttpException(401, 'INVALID_TOKEN', [], 'Invalid token');
        }

        if (($header['alg'] ?? '') !== 'HS256') {
            throw new HttpException(401, 'INVALID_TOKEN', [], 'Invalid token algorithm');
        }

        $expected = hash_hmac('sha256', $h64 . '.' . $p64, $secret, true);
        if (!hash_equals($expected, $sig)) {
            throw new HttpException(401, 'INVALID_TOKEN', [], 'Invalid token signature');
        }

        // exp
        $exp = (int)($payload['exp'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            throw new HttpException(401, 'TOKEN_EXPIRED', [], 'Token expired');
        }

        return $payload;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new HttpException(401, 'INVALID_TOKEN', [], 'Invalid token encoding');
        }
        return $decoded;
    }
}
