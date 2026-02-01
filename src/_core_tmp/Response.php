<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null, int $status = 200, array $meta = []): void
    {
        self::json([
            'success' => true,
            'data' => $data,
            'meta' => (object)$meta,
        ], $status);
    }

    public static function created(mixed $data = null, array $meta = []): void
    {
        self::ok($data, 201, $meta);
    }

    public static function noContent(): void
    {
        http_response_code(204);
        exit;
    }

    /**
     * 2 signatures supportées :
     *  A) Ancien style : error(string $message, int $status=400, array $details=[])
     *  B) Nouveau style : error(string $code, string $message, int $status=400, array $details=[], array $meta=[])
     */
    public static function error(
        string $code,
        string $message,
        int $status = 400,
        array $details = [],
        array $meta = []
    ): void {
        self::json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object)$details,
            ],
            'meta' => (object)$meta,
        ], $status);
    }

    public static function legacyError(string $message, int $status = 400, array $details = []): void
    {
        $code = self::statusToCode($status);
        self::error($code, $message, $status, $details);
    }



    private static function statusToCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMITED',
            default => 'ERROR',
        };
    }
}
