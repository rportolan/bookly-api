<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class ActivityRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    /**
     * Record or update today's reading for a specific book.
     *
     * Uses ABSOLUTE tracking instead of deltas:
     *   pages_today = max(0, newProgress - day_start_pages)
     *
     * day_start_pages = the book's progress_pages BEFORE any update today.
     * It is captured once on the first update of the day and never changed.
     *
     * This means corrections work correctly:
     *   - 0 → 50  : start=0, today=50
     *   - 50 → 20 : start=0, today=20  ✓ (correction)
     *   - 20 → 30 : start=0, today=30  ✓ (not 50+10)
     *
     * After updating the session, reading_logs is always recomputed as the
     * SUM of all sessions for that day (never accumulated additively).
     */
    public function logSession(int $userId, int $userBookId, int $oldPages, int $newPages): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        // Fetch the stored start-of-day baseline (set only on the first update of the day)
        $stmt = $this->pdo->prepare("
            SELECT day_start_pages
            FROM reading_sessions
            WHERE user_id = :uid AND user_book_id = :ub AND day = :day
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId, 'ub' => $userBookId, 'day' => $today]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        // First update today → use oldPages as the baseline
        // Subsequent updates → use the stored baseline (ignore oldPages)
        $dayStart   = $existing !== false ? (int)$existing['day_start_pages'] : $oldPages;
        $todayPages = max(0, $newPages - $dayStart);

        // Upsert session — only update the pages column on duplicate, never day_start_pages
        $this->pdo->prepare("
            INSERT INTO reading_sessions (user_id, user_book_id, day, pages, day_start_pages)
            VALUES (:uid, :ub, :day, :pages, :start)
            ON DUPLICATE KEY UPDATE pages = :pages
        ")->execute([
            'uid'   => $userId,
            'ub'    => $userBookId,
            'day'   => $today,
            'pages' => $todayPages,
            'start' => $dayStart,
        ]);

        // Recompute today's reading_logs entry (always SET, never ADD)
        $this->recomputeLog($userId, $today);
    }

    /**
     * Returns the days (ISO strings) that have reading sessions for a given book.
     * Call BEFORE deleting the book so we know which log days to recompute after cascade.
     */
    public function getDaysWithSessions(int $userId, int $userBookId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT day
            FROM reading_sessions
            WHERE user_id = :uid AND user_book_id = :ub
        ");
        $stmt->execute(['uid' => $userId, 'ub' => $userBookId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Recompute reading_logs for a list of days.
     * Call AFTER a book is deleted (its sessions are gone via CASCADE) to correct
     * the daily totals that included that book's pages.
     */
    public function recomputeLogsForDays(int $userId, array $days): void
    {
        foreach ($days as $day) {
            $this->recomputeLog($userId, (string)$day);
        }
    }

    /**
     * Recompute reading_logs for a single day from the SUM of remaining sessions.
     */
    private function recomputeLog(int $userId, string $day): void
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(pages), 0)
            FROM reading_sessions
            WHERE user_id = :uid AND day = :day
        ");
        $stmt->execute(['uid' => $userId, 'day' => $day]);
        $total = (int)$stmt->fetchColumn();

        $this->pdo->prepare("
            INSERT INTO reading_logs (user_id, day, pages)
            VALUES (:uid, :day, :pages)
            ON DUPLICATE KEY UPDATE pages = :pages
        ")->execute(['uid' => $userId, 'day' => $day, 'pages' => $total]);
    }

    /**
     * Pages read today by the user (sum across all books, from reading_sessions).
     */
    public function getDailyPages(int $userId, string $day): int
    {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(pages), 0)
            FROM reading_sessions
            WHERE user_id = :uid AND day = :day
        ");
        $stmt->execute(['uid' => $userId, 'day' => $day]);
        return (int)$stmt->fetchColumn();
    }

    /** User's daily reading goal (goal_pages_per_day). */
    public function getUserGoal(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT goal_pages_per_day FROM users WHERE id = :uid LIMIT 1"
        );
        $stmt->execute(['uid' => $userId]);
        return max(1, (int)($stmt->fetchColumn() ?: 20));
    }

    /**
     * Consecutive days where reading_logs.pages >= goalPages, going back from today.
     * Returns 0 if today's goal isn't met yet.
     */
    public function computeStreak(int $userId, int $goalPages): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT day FROM reading_logs
             WHERE user_id = :uid AND pages >= :goal
             ORDER BY day DESC"
        );
        $stmt->execute(['uid' => $userId, 'goal' => $goalPages]);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $byDay  = array_flip($rows);
        $streak = 0;
        $cursor = new \DateTimeImmutable('today');

        while (isset($byDay[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor = $cursor->modify('-1 day');
        }

        return $streak; // 0 = goal not yet met today
    }

    /**
     * Full activity data for the last $weeks weeks.
     */
    public function fullActivity(int $userId, int $weeks = 26): array
    {
        $since = (new \DateTimeImmutable("today - {$weeks} weeks"))->format('Y-m-d');

        // Daily totals (from reading_sessions — always up-to-date)
        $stmt = $this->pdo->prepare("
            SELECT day, SUM(pages) AS pages
            FROM reading_sessions
            WHERE user_id = :uid AND day >= :since
            GROUP BY day
            ORDER BY day ASC
        ");
        $stmt->execute(['uid' => $userId, 'since' => $since]);
        $dailyRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Reading goal
        $gStmt = $this->pdo->prepare("SELECT goal_pages_per_day FROM users WHERE id = :uid LIMIT 1");
        $gStmt->execute(['uid' => $userId]);
        $goal = (int)($gStmt->fetchColumn() ?: 20);

        $calendar = [];
        foreach ($dailyRows as $row) {
            $pages = (int)$row['pages'];
            $pct   = $goal > 0 ? ($pages / $goal) : 1;
            $level = match (true) {
                $pct <= 0   => 0,
                $pct < 0.25 => 1,
                $pct < 0.5  => 2,
                $pct < 1.0  => 3,
                default     => 4,
            };
            $calendar[$row['day']] = ['pages' => $pages, 'level' => $level];
        }

        // Per-day sessions with book info
        $stmt = $this->pdo->prepare("
            SELECT rs.day,
                   rs.user_book_id,
                   rs.pages,
                   b.title,
                   b.author,
                   b.cover_url AS coverUrl
            FROM reading_sessions rs
            JOIN user_books ub ON ub.id = rs.user_book_id
            JOIN books      b  ON b.id  = ub.book_id
            WHERE rs.user_id = :uid AND rs.day >= :since AND rs.pages > 0
            ORDER BY rs.day DESC, rs.pages DESC
        ");
        $stmt->execute(['uid' => $userId, 'since' => $since]);
        $sessionRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $sessions = [];
        foreach ($sessionRows as $row) {
            $sessions[$row['day']][] = [
                'userBookId' => (int)$row['user_book_id'],
                'title'      => $row['title'],
                'author'     => $row['author'],
                'coverUrl'   => $row['coverUrl'],
                'pages'      => (int)$row['pages'],
            ];
        }

        // Books finished per day
        $stmt = $this->pdo->prepare("
            SELECT DATE(ub.finished_at) AS day,
                   ub.id AS userBookId,
                   b.title, b.author, b.cover_url AS coverUrl,
                   b.pages, ub.rating
            FROM user_books ub
            JOIN books b ON b.id = ub.book_id
            WHERE ub.user_id = :uid
              AND ub.status = 'Terminé'
              AND ub.finished_at IS NOT NULL
              AND DATE(ub.finished_at) >= :since
        ");
        $stmt->execute(['uid' => $userId, 'since' => $since]);

        $finished = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $finished[$row['day']][] = [
                'userBookId' => (int)$row['userBookId'],
                'title'      => $row['title'],
                'author'     => $row['author'],
                'coverUrl'   => $row['coverUrl'],
                'pages'      => (int)$row['pages'],
                'rating'     => $row['rating'] !== null ? (float)$row['rating'] : null,
            ];
        }

        // Books added per day
        $stmt = $this->pdo->prepare("
            SELECT DATE(ub.created_at) AS day,
                   ub.id AS userBookId,
                   b.title, b.author, b.cover_url AS coverUrl
            FROM user_books ub
            JOIN books b ON b.id = ub.book_id
            WHERE ub.user_id = :uid
              AND DATE(ub.created_at) >= :since
        ");
        $stmt->execute(['uid' => $userId, 'since' => $since]);

        $added = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $added[$row['day']][] = [
                'userBookId' => (int)$row['userBookId'],
                'title'      => $row['title'],
                'author'     => $row['author'],
                'coverUrl'   => $row['coverUrl'],
            ];
        }

        return [
            'goal'     => $goal,
            'calendar' => $calendar,
            'sessions' => $sessions,
            'finished' => $finished,
            'added'    => $added,
        ];
    }
}
