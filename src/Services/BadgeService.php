<?php
namespace App\Services;

use App\Repositories\BadgeRepository;

final class BadgeService
{
    private BadgeRepository $badgeRepo;

    public function __construct()
    {
        $this->badgeRepo = new BadgeRepository();
    }

    public function computeBadges(int $userId, array $stats): array
    {
        $unlockedKeys = $this->badgeRepo->listKeys($userId);
        $unlockedSet = array_fill_keys($unlockedKeys, true);

        $badges = [];
        foreach (BadgeCatalog::all() as $b) {
            $value = 0;

            if (!empty($b['metric'])) {
                $value = (int)($stats[$b['metric']] ?? 0);
            }

            $target = (int)($b['target'] ?? 0);
            $done = $target > 0 ? ($value >= $target) : isset($unlockedSet[$b['key']]); // FIRST_* etc
            $pct = $target > 0 ? (int)round(($value / max(1, $target)) * 100) : ($done ? 100 : 0);
            $pct = max(0, min(100, $pct));

            // Auto-unlock si c’est un badge à seuil
            if ($target > 0 && $done && !isset($unlockedSet[$b['key']])) {
                $this->badgeRepo->unlock($userId, (string)$b['key']);
                $unlockedSet[$b['key']] = true;
            }

            $badges[] = [
                'id' => (string)$b['key'],
                'cat' => (string)$b['cat'],
                'icon' => (string)$b['icon'],
                'title' => (string)$b['title'],
                'desc' => (string)$b['desc'],
                'value' => $value,
                'target' => $target,
                'pct' => $pct,
                'done' => $done,
                'unlocked' => isset($unlockedSet[$b['key']]),
            ];
        }

        return $badges;
    }
}
