<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProgressRepository;
use App\Repositories\BadgeRepository;

final class ProgressService
{
    private array $levelThresholds = [
        0, 100, 250, 450, 700, 1000, 1400, 1900, 2500, 3200, 4000, 5000, 6200, 7600, 9200,
    ];

    private array $titles = [
        ['minLevel' => 1,  'name' => 'Lecteur novice'],
        ['minLevel' => 5,  'name' => 'Lecteur motivé'],
        ['minLevel' => 10, 'name' => 'Lecteur confirmé'],
        ['minLevel' => 15, 'name' => 'Lecteur expert'],
        ['minLevel' => 20, 'name' => 'Grand lecteur'],
        ['minLevel' => 30, 'name' => 'Bibliophage'],
    ];

    private ProgressRepository $progressRepo;
    private BadgeRepository $badgeRepo;

    public function __construct()
    {
        $this->progressRepo = new ProgressRepository();
        $this->badgeRepo = new BadgeRepository();
    }

    /**
     * Ajoute de l'XP + log event + badges, puis renvoie un snapshot complet.
     */
    public function award(int $userId, string $type, int $delta, array $meta = []): array
    {
        $delta = (int)$delta;
        if ($delta === 0) {
            return $this->snapshot($userId);
        }

        // 1) Log event
        $this->progressRepo->addXpEvent($userId, $type, $delta, $meta);

        // 2) Update user xp
        $this->progressRepo->addXpToUser($userId, $delta);

        // 3) Badges MVP
        $this->checkAndUnlockBadgesMvp($userId, $type);

        return $this->snapshot($userId);
    }

    public function snapshot(int $userId): array
    {
        $xp = $this->progressRepo->getUserXp($userId);
        $level = $this->computeLevel($xp);
        $title = $this->computeTitle($level);
        $badges = $this->badgeRepo->listKeys($userId);

        return [
            'xp' => $xp,
            'level' => $level,
            'title' => $title,
            'badges' => $badges,
        ];
    }

    public function computeLevel(int $xp): int
    {
        $level = 1;
        foreach ($this->levelThresholds as $i => $minXp) {
            if ($xp >= $minXp) $level = $i + 1;
        }
        return $level;
    }

    public function computeTitle(int $level): string
    {
        $title = 'Lecteur novice';
        foreach ($this->titles as $t) {
            if ($level >= (int)$t['minLevel']) {
                $title = (string)$t['name'];
            }
        }
        return $title;
    }

    private function checkAndUnlockBadgesMvp(int $userId, string $type): void
    {
        switch ($type) {
            case 'BOOK_CREATED':
                $this->badgeRepo->unlock($userId, 'FIRST_BOOK');
                break;
            case 'QUOTE_CREATED':
                $this->badgeRepo->unlock($userId, 'FIRST_QUOTE');
                break;
            case 'VOCAB_CREATED':
                $this->badgeRepo->unlock($userId, 'FIRST_VOCAB');
                break;
            case 'LEARN_SESSION_DONE':
                $this->badgeRepo->unlock($userId, 'FIRST_LEARN_SESSION');
                break;
        }
    }
}
