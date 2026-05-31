<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\StatsRepository;

final class StatsController
{
    public function __construct(
        private StatsRepository $repo = new StatsRepository()
    ) {}

    public function index(): void
    {
        $uid = Auth::requireAuth();
        Response::ok($this->repo->fullStats($uid));
    }
}
