<?php
namespace App\Controllers;

use App\Core\Response;

final class HealthController
{
    public function __invoke(): void
    {
        Response::ok([
            'ok' => true,
            'service' => 'bookly-api',
        ]);
    }
}