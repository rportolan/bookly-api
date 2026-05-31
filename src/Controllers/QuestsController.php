<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\QuestRepository;
use App\Services\ProgressService;

final class QuestsController
{
    public function summary(): void
    {
        $uid = Auth::requireAuth();

        $stats = (new QuestRepository())->statsForUser($uid);
        $progress = (new ProgressService())->snapshot($uid);

        Response::ok([
            'stats' => $stats,
            'progress' => $progress,
        ]);
    }
}
