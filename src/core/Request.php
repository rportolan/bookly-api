<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public static function json(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') return [];

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException(
                400,
                'INVALID_JSON',
                ['error' => json_last_error_msg()],
                'Invalid JSON body'
            );
        }

        if (!is_array($data)) {
            throw new HttpException(400, 'INVALID_JSON', [], 'JSON body must be an object');
        }

        return $data;
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
    }

    public static function header(string $name, ?string $default = null): ?string
    {
        $nameNorm = strtolower($name);

        // 1) cas classique: $_SERVER['HTTP_AUTHORIZATION']
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$key])) {
            return trim((string)$_SERVER[$key]);
        }

        // 2) parfois Apache met ça ailleurs
        if ($nameNorm === 'authorization') {
            if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
            }
            if (!empty($_SERVER['Authorization'])) {
                return trim((string)$_SERVER['Authorization']);
            }
        }

        // 3) fallback getallheaders()
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $k => $v) {
                if (strtolower((string)$k) === $nameNorm) {
                    return trim((string)$v);
                }
            }
        }

        return $default;
    }

}
