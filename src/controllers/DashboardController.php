<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\DashboardRepository;

final class DashboardController
{
    public function __construct(
        private DashboardRepository $repo = new DashboardRepository()
    ) {}

    /**
     * GET /api/dashboard/continue
     */
    public function continue(): void
    {
        $uid = Auth::requireAuth();

        $book = $this->repo->lastReadBook($uid);

        Response::ok([
            'book' => $book, // null si aucun livre
        ]);
    }
}
