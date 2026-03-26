<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProgressRepository;

final class ProgressService
{
    private array $levelThresholds = [
        0, 80, 180, 320, 500, 720, 980, 1280, 1620, 2000, 2450, 2950, 3500, 4100, 4750,
        5450, 6200, 7000, 7850, 8750
    ];

    private array $titles = [
        ['minLevel' => 1,  'name' => 'Lecteur novice'],
        ['minLevel' => 5,  'name' => 'Lecteur motivé'],
        ['minLevel' => 10, 'name' => 'Lecteur confirmé'],
        ['minLevel' => 15, 'name' => 'Lecteur expert'],
        ['minLevel' => 20, 'name' => 'Grand lecteur'],
        ['minLevel' => 30, 'name' => 'Bibliophage'],
    ];

    private array $xpRewards = [
        'BOOK_CREATED'         => 80,
        'QUOTE_CREATED'        => 25,
        'VOCAB_CREATED'        => 20,
        'CHAPTER_ADDED'        => 20,
        'ANALYSIS_ADDED'       => 60,
        'LEARN_SESSION_DONE'   => 60,
        'PAGES_READ'           => 2,
        'BOOK_DONE'            => 150,
        'STREAK_DAY'           => 40,
        'QUIZ_COMPLETED'       => 50,
        'DAILY_GOAL_COMPLETED' => 10,
    ];

    private ProgressRepository $progressRepo;

    public function __construct()
    {
        $this->progressRepo = new ProgressRepository();
    }

    public function award(int $userId, string $type, int $delta = 0, array $meta = []): array
    {
        if ($delta === 0) {
            $delta = $this->computeDeltaFromType($type, $meta);
        }

        if ($delta === 0) {
            (new CardService())->checkUnlocks($userId);
            return $this->snapshot($userId);
        }

        // ✅ Capturer le niveau AVANT l'ajout d'XP pour détecter le level-up
        $xpBefore    = $this->progressRepo->getUserXp($userId);
        $levelBefore = $this->computeLevel($xpBefore);

        $this->progressRepo->addXpEvent($userId, $type, $delta, $meta);
        $this->progressRepo->addXpToUser($userId, $delta);

        (new CardService())->checkUnlocks($userId);

        return $this->snapshot($userId, $levelBefore);
    }

    /**
     * @param int|null $previousLevel  Niveau avant l'award. Si fourni et inférieur au nouveau niveau,
     *                                  le snapshot contiendra leveledUp=true et previousLevel.
     */
    public function snapshot(int $userId, ?int $previousLevel = null): array
    {
        $xp    = $this->progressRepo->getUserXp($userId);
        $level = $this->computeLevel($xp);
        $title = $this->computeTitle($level);

        $minXp     = $this->getLevelMinXp($level);
        $nextMinXp = $this->getLevelMinXp($level + 1);

        $xpThisLevel = max(0, $xp - $minXp);
        $xpToNext    = max(0, $nextMinXp - $xp);
        $span        = max(1, $nextMinXp - $minXp);

        $leveledUp = $previousLevel !== null && $level > $previousLevel;

        return [
            'xp'            => $xp,
            'level'         => $level,
            'title'         => $title,
            'progressPct'   => (int)round(($xpThisLevel / $span) * 100),
            'xpToNext'      => $xpToNext,
            // ✅ Toujours présents — le front peut toujours les lire
            'leveledUp'     => $leveledUp,
            'previousLevel' => $leveledUp ? $previousLevel : $level,
        ];
    }

    private function computeLevel(int $xp): int
    {
        $level = 1;
        foreach ($this->levelThresholds as $i => $minXp) {
            if ($xp >= $minXp) {
                $level = $i + 1;
            }
        }
        return $level;
    }

    private function computeTitle(int $level): string
    {
        $title = 'Lecteur novice';
        foreach ($this->titles as $t) {
            if ($level >= $t['minLevel']) {
                $title = $t['name'];
            }
        }
        return $title;
    }

    private function getLevelMinXp(int $level): int
    {
        $idx = max(0, $level - 1);
        return $this->levelThresholds[$idx] ?? (int)end($this->levelThresholds);
    }

    private function computeDeltaFromType(string $type, array $meta): int
    {
        $base = $this->xpRewards[$type] ?? 0;

        if ($type === 'PAGES_READ') {
            $pages = (int)($meta['pages'] ?? 0);
            return $pages * max(1, (int)$base);
        }

        return (int)$base;
    }
}