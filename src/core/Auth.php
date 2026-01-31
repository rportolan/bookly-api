<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public static function userIdFromBearer(): int
    {
        $auth = Request::header('Authorization', '');
        if ($auth === null || $auth === '' || !str_starts_with($auth, 'Bearer ')) {
            return 0;
        }

        $token = trim(substr($auth, 7));
        if ($token === '') return 0;

        $secret = Env::get('JWT_SECRET', '');
        if ($secret === '') {
            // Misconfig = 500 (normal)
            throw new HttpException(500, 'SERVER_MISCONFIG', [], 'JWT_SECRET is missing');
        }

        try {
            $payload = Jwt::decode($token, $secret);
        } catch (\Throwable $e) {
            // Token invalide/expiré/signature KO => 401 (pas 500)
            throw new HttpException(401, 'UNAUTHORIZED', ['reason' => 'invalid_token'], 'Unauthorized');
        }

        $sub = (int)($payload['sub'] ?? 0);
        return $sub > 0 ? $sub : 0;
    }

    public static function requireAuth(): int
    {
        $uid = self::userIdFromBearer();
        if ($uid <= 0) {
            throw new HttpException(401, 'UNAUTHORIZED', [], 'Unauthorized');
        }
        return $uid;
    }
}
