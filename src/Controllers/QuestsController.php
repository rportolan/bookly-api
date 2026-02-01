<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\QuestRepository;
use App\Services\ProgressService;
use App\Services\BadgeService;

final class QuestsController
{
    public function summary(): void
    {
        $uid = Auth::requireAuth();

        $questRepo = new QuestRepository();
        $stats = $questRepo->statsForUser($uid);

        $progressService = new ProgressService();
        $progress = $progressService->snapshot($uid);

        $badgeService = new BadgeService();
        $badges = $badgeService->computeBadges($uid, $stats);

        Response::ok([
            'stats' => $stats,
            'progress' => $progress,
            'badges' => $badges,
        ]);
    }
}
