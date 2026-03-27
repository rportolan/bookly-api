<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ReadingRepository;
use App\Repositories\ProgressRepository;
use App\Services\CardService;
use App\Services\ProgressService;

final class ReadingController
{
    public function __construct(
        private ReadingRepository $repo = new ReadingRepository()
    ) {}

    public function getGoal(): void
    {
        $userId = Auth::requireAuth();
        $goal = $this->repo->getGoalPagesPerDay($userId);

        Response::ok([
            'goalPagesPerDay' => $goal,
        ]);
    }

    public function updateGoal(): void
    {
        $userId = Auth::requireAuth();
        $body = Request::json();

        $goalRaw = $body['goalPagesPerDay'] ?? null;
        if ($goalRaw === null) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'goalPagesPerDay'], 'goalPagesPerDay is required');
        }

        $goal = (int)$goalRaw;
        $goal = max(1, min(5000, $goal));

        $this->repo->setGoalPagesPerDay($userId, $goal);

        Response::ok([
            'goalPagesPerDay' => $goal,
        ]);
    }

    public function getLog(): void
    {
        $userId = Auth::requireAuth();

        $to = Request::query('to');
        $from = Request::query('from');

        if (!$to) {
            $to = (new \DateTimeImmutable('today'))->format('Y-m-d');
        }
        if (!$from) {
            $from = (new \DateTimeImmutable('today'))
                ->sub(new \DateInterval('P400D'))
                ->format('Y-m-d');
        }

        if (!$this->isDate($from) || !$this->isDate($to)) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['fields' => ['from', 'to']], 'Invalid date range');
        }

        if ($from > $to) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['fields' => ['from', 'to']], 'from must be <= to');
        }

        $entries = $this->repo->getLogsInRange($userId, $from, $to);

        Response::ok([
            'entries' => $entries,
        ]);
    }

    public function upsertToday(): void
    {
        $userId = Auth::requireAuth();
        $body = Request::json();

        $pagesRaw = $body['pages'] ?? null;
        if ($pagesRaw === null) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'pages'], 'pages is required');
        }

        $pages = (int)$pagesRaw;
        $pages = max(0, min(5000, $pages));

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $beforeRow = $this->repo->getLogByDay($userId, $today);
        $beforePages = (int)($beforeRow['pages'] ?? 0);

        $goal = (int)$this->repo->getGoalPagesPerDay($userId);

        $progressService = new ProgressService();
        $cardService = new CardService();
        $beforeProgress = $progressService->snapshot($userId);

        $this->repo->upsertLog($userId, $today, $pages);

        $crossedGoal = ($goal > 0 && $beforePages < $goal && $pages >= $goal);

        $rewardedGoal = false;
        $rewardedStreak = false;
        $streakDays = $this->repo->computeCurrentStreakDays($userId);

        $newUnlockedCards = [];

        if ($crossedGoal && !$this->repo->hasDailyGoalXp($userId, $today)) {
            try {
                $goalProgress = $progressService->award($userId, 'DAILY_GOAL_COMPLETED', 0, [
                    'day' => $today,
                    'goal' => $goal,
                    'pages' => $pages,
                ]);
                $newUnlockedCards = $this->mergeUnlockedCards(
                    $newUnlockedCards,
                    $goalProgress['cardUnlock']['cards'] ?? []
                );
                $rewardedGoal = true;
            } catch (\Throwable $e) {
                error_log('[BOOKLY][XP] award DAILY_GOAL_COMPLETED failed: ' . $e->getMessage());
            }
        }

        if ($pages > 0) {
            try {
                $pr = new ProgressRepository();
                $already = $pr->hasEventMeta($userId, 'STREAK_DAY', 'day', $today);
                if (!$already) {
                    $streakProgress = $progressService->award($userId, 'STREAK_DAY', 0, [
                        'day' => $today,
                        'pages' => $pages,
                        'streakDays' => $streakDays,
                    ]);
                    $newUnlockedCards = $this->mergeUnlockedCards(
                        $newUnlockedCards,
                        $streakProgress['cardUnlock']['cards'] ?? []
                    );
                    $rewardedStreak = true;
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][XP] award STREAK_DAY failed: ' . $e->getMessage());
            }
        }

        try {
            $extraUnlocked = $cardService->checkUnlocks($userId);
            $newUnlockedCards = $this->mergeUnlockedCards($newUnlockedCards, $extraUnlocked);
        } catch (\Throwable $e) {
            error_log('[BOOKLY][cards] checkUnlocks failed: ' . $e->getMessage());
        }

        $afterProgress = $progressService->snapshot($userId);
        $levelUp = $progressService->buildLevelUpPayload($beforeProgress, $afterProgress);

        Response::ok([
            'entry' => ['date' => $today, 'pages' => $pages],
            'goalPagesPerDay' => $goal,
            'crossedGoalToday' => $crossedGoal,
            'rewardedDailyGoal' => $rewardedGoal,
            'rewardedStreakDay' => $rewardedStreak,
            'streakDays' => $streakDays,
            'progress' => $afterProgress,
            'levelUp' => $levelUp,
            'cardUnlock' => [
                'happened' => count($newUnlockedCards) > 0,
                'cards' => array_values($newUnlockedCards),
            ],
            'awardedXp' => max(0, (int)$afterProgress['xp'] - (int)$beforeProgress['xp']),
            'awardType' => 'READING_LOG_UPDATED',
        ]);
    }

    private function mergeUnlockedCards(array $base, array $incoming): array
    {
        $map = [];
        foreach ($base as $card) {
            $id = (int)($card['id'] ?? 0);
            if ($id > 0) $map[$id] = $card;
        }
        foreach ($incoming as $card) {
            $id = (int)($card['id'] ?? 0);
            if ($id > 0) $map[$id] = $card;
        }
        return array_values($map);
    }

    private function isDate(string $s): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        return $dt && $dt->format('Y-m-d') === $s;
    }
}