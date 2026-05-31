<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\ActivityRepository;

final class ActivityController
{
    public function __construct(
        private ActivityRepository $repo = new ActivityRepository()
    ) {}

    public function index(): void
    {
        $uid = Auth::requireAuth();
        Response::ok($this->repo->fullActivity($uid));
    }
}
