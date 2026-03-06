<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\CardRepository;
use App\Repositories\QuestRepository;

final class CardService
{
    private CardRepository $repo;

    public function __construct()
    {
        $this->repo = new CardRepository();
    }

    public function checkUnlocks(int $userId): void
    {
        $cards = $this->repo->all();
        $stats = (new QuestRepository())->statsForUser($userId);
        $level = (new ProgressService())->snapshot($userId)['level'];

        foreach ($cards as $card) {
            $cardId = (int)$card['id'];

            // déjà possédée (unclaimed/claimed)
            if ($this->repo->userHas($userId, $cardId)) {
                continue;
            }

            $need = (int)($card['unlock_value'] ?? 0);
            $unlock = false;

            switch ((string)$card['unlock_type']) {
                case 'LEVEL_REACHED':
                    $unlock = $level >= $need;
                    break;

                case 'BOOKS_FINISHED':
                    $unlock = (int)($stats['done'] ?? 0) >= $need;
                    break;

                case 'PAGES_READ':
                    $unlock = (int)($stats['pagesRead'] ?? 0) >= $need;
                    break;

                case 'VOCAB_ADDED':
                    $unlock = (int)($stats['vocab'] ?? 0) >= $need;
                    break;

                case 'QUOTES_ADDED':
                    $unlock = (int)($stats['quotes'] ?? 0) >= $need;
                    break;

                case 'BOOKS_CREATED':
                    $unlock = (int)($stats['booksCreated'] ?? 0) >= $need;
                    break;

                case 'ANALYSES_ADDED':
                    $unlock = (int)($stats['analyses'] ?? 0) >= $need;
                    break;

                case 'CHAPTERS_ADDED':
                    $unlock = (int)($stats['chapters'] ?? 0) >= $need;
                    break;

                case 'STREAK_DAYS':
                    $unlock = (int)($stats['streakDays'] ?? 0) >= $need;
                    break;

                case 'QUIZZES_COMPLETED':
                    $unlock = (int)($stats['quizzesCompleted'] ?? 0) >= $need;
                    break;
            }

            if ($unlock) {
                $this->repo->unlock($userId, $cardId);
            }
        }
    }
}