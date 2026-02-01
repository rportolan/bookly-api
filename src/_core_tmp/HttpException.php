<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $details = [],
        string $message = ''
    ) {
        parent::__construct($message ?: $errorCode, $status);
    }
}
