<?php
// src/Controllers/ReadingController.php
//
// Modification par rapport à l'original :
// upsertToday() capture le niveau AVANT les awards,
// puis renvoie un snapshot final avec leveledUp: true si le niveau a monté.
//
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
        Response::ok(['goalPagesPerDay' => $goal]);
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
        Response::ok(['goalPagesPerDay' => $goal]);
    }

    public function getLog(): void
    {
        $userId = Auth::requireAuth();

        $to   = Request::query('to');
        $from = Request::query('from');

        if (!$to)   $to   = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (!$from) $from = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P400D'))->format('Y-m-d');

        if (!$this->isDate($from) || !$this->isDate($to)) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['fields' => ['from', 'to']], 'Invalid date range');
        }
        if ($from > $to) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['fields' => ['from', 'to']], 'from must be <= to');
        }

        $entries = $this->repo->getLogsInRange($userId, $from, $to);
        Response::ok(['entries' => $entries]);
    }

    public function upsertToday(): void
    {
        $userId = Auth::requireAuth();
        $body   = Request::json();

        $pagesRaw = $body['pages'] ?? null;
        if ($pagesRaw === null) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'pages'], 'pages is required');
        }

        $pages = max(0, min(5000, (int)$pagesRaw));
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $beforeRow   = $this->repo->getLogByDay($userId, $today);
        $beforePages = (int)($beforeRow['pages'] ?? 0);
        $goal        = (int)$this->repo->getGoalPagesPerDay($userId);

        $this->repo->upsertLog($userId, $today, $pages);

        $crossedGoal = ($goal > 0 && $beforePages < $goal && $pages >= $goal);

        $ps = new ProgressService();

        // ✅ On capture le niveau AVANT tous les awards
        $levelBefore    = $ps->getLevelForUser($userId);
        $rewardedGoal   = false;
        $rewardedStreak = false;
        $streakDays     = $this->repo->computeCurrentStreakDays($userId);

        // Award objectif du jour
        if ($crossedGoal && !$this->repo->hasDailyGoalXp($userId, $today)) {
            try {
                $ps->award($userId, 'DAILY_GOAL_COMPLETED', 0, ['day' => $today, 'goal' => $goal, 'pages' => $pages]);
                $rewardedGoal = true;
            } catch (\Throwable $e) {
                error_log('[BOOKLY][XP] award DAILY_GOAL_COMPLETED failed: ' . $e->getMessage());
            }
        }

        // Award streak du jour
        if ($pages > 0) {
            try {
                $pr = new ProgressRepository();
                if (!$pr->hasEventMeta($userId, 'STREAK_DAY', 'day', $today)) {
                    $ps->award($userId, 'STREAK_DAY', 0, ['day' => $today, 'pages' => $pages, 'streakDays' => $streakDays]);
                    $rewardedStreak = true;
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][XP] award STREAK_DAY failed: ' . $e->getMessage());
            }
        }

        // Check unlocks cartes
        try {
            (new CardService())->checkUnlocks($userId);
        } catch (\Throwable $e) {
            error_log('[BOOKLY][cards] checkUnlocks failed: ' . $e->getMessage());
        }

        // ✅ Snapshot final avec leveledUp calculé par rapport au niveau d'avant
        $progress = $ps->snapshot($userId, $levelBefore);

        Response::ok([
            'entry'             => ['date' => $today, 'pages' => $pages],
            'goalPagesPerDay'   => $goal,
            'crossedGoalToday'  => $crossedGoal,
            'rewardedDailyGoal' => $rewardedGoal,
            'rewardedStreakDay'  => $rewardedStreak,
            'streakDays'        => $streakDays,
            'progress'          => $progress, // ✅ toujours présent, avec leveledUp
        ]);
    }

    private function isDate(string $s): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        return $dt && $dt->format('Y-m-d') === $s;
    }
}