<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ReadingRepository;
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

        // garde-fou (si from > to)
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

        // 1) previous pages
        $beforeRow = $this->repo->getLogByDay($userId, $today);
        $beforePages = (int)($beforeRow['pages'] ?? 0);

        // 2) goal
        $goal = (int)$this->repo->getGoalPagesPerDay($userId);

        // 3) save new value
        $this->repo->upsertLog($userId, $today, $pages);

        // 4) reward XP once when crossing threshold
        $crossed = ($goal > 0 && $beforePages < $goal && $pages >= $goal);

        $rewarded = false;
        $progress = null;

        if ($crossed && !$this->repo->hasDailyGoalXp($userId, $today)) {
            $xp = 10; // ajuste quand tu veux

            try {
                $ps = new ProgressService();
                $progress = $ps->award($userId, 'daily_goal_completed', $xp, [
                    'day' => $today,
                    'goal' => $goal,
                    'pages' => $pages,
                ]);
                $rewarded = true;
            } catch (\Throwable $e) {
                error_log('[BOOKLY][XP] award daily_goal_completed failed: ' . $e->getMessage());
            }
        }

        Response::ok([
            'entry' => ['date' => $today, 'pages' => $pages],
            'goalPagesPerDay' => $goal,
            'crossedGoalToday' => $crossed,
            'rewardedToday' => $rewarded,
            'progress' => $progress, // null si pas de reward
        ]);
    }

    private function isDate(string $s): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $s);
        return $dt && $dt->format('Y-m-d') === $s;
    }
}
