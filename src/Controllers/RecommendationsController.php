<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\RecommendationRepository;

final class RecommendationsController
{
    public function index(): void
    {
        Auth::requireAuth();

        $month = (new \DateTimeImmutable('today'))->format('Y-m');
        $items = (new RecommendationRepository())->listByMonth($month);

        Response::ok([
            'recommendations' => $items,
            'month'           => $month,
        ]);
    }
}
