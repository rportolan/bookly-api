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

    /**
     * Retourne UNIQUEMENT les cartes nouvellement débloquées pendant cet appel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function checkUnlocks(int $userId): array
    {
        $cards = $this->repo->all();
        $stats = (new QuestRepository())->statsForUser($userId);
        $level = (new ProgressService())->snapshot($userId)['level'];

        $newlyUnlocked = [];

        foreach ($cards as $card) {
            $cardId = (int)($card['id'] ?? 0);
            if ($cardId <= 0) {
                continue;
            }

            if ($this->repo->userHas($userId, $cardId)) {
                continue;
            }

            $need = (int)($card['unlock_value'] ?? 0);
            $unlock = false;

            switch ((string)($card['unlock_type'] ?? '')) {
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

            if (!$unlock) {
                continue;
            }

            $unlocked = $this->unlockById($userId, $cardId);
            if ($unlocked) {
                $newlyUnlocked[] = $unlocked;
            }
        }

        return $newlyUnlocked;
    }

    /**
     * Débloque explicitement une carte si elle n'est pas encore possédée.
     */
    public function unlockById(int $userId, int $cardId): ?array
    {
        if ($cardId <= 0) {
            return null;
        }

        if ($this->repo->userHas($userId, $cardId)) {
            return null;
        }

        $this->repo->unlock($userId, $cardId);

        return $this->repo->findForUser($userId, $cardId);
    }
}