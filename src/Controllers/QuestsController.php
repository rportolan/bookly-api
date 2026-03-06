<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\QuestRepository;
use App\Repositories\CardRepository;
use App\Services\ProgressService;
use App\Services\CardService;

final class QuestsController
{
    public function summary(): void
    {
        $uid = Auth::requireAuth();

        $stats = (new QuestRepository())->statsForUser($uid);
        $progress = (new ProgressService())->snapshot($uid);

        // 🔥 Vérifie les unlocks
        (new CardService())->checkUnlocks($uid);

        $cards = (new CardRepository())->listForUser($uid);

        Response::ok([
            'stats' => $stats,
            'progress' => $progress,
            'cards' => $cards,
        ]);
    }
}