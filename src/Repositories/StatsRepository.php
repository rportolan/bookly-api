<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class StatsRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function fullStats(int $userId): array
    {
        return [
            'reading'  => $this->readingStats($userId),
            'library'  => $this->libraryStats($userId),
            'learning' => $this->learningStats($userId),
        ];
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    private function readingStats(int $userId): array
    {
        // Total pages and daily record (all reading, regardless of goal)
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(pages), 0) AS total, COALESCE(MAX(pages), 0) AS record
            FROM reading_logs
            WHERE user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $totalPages  = (int)($row['total']  ?? 0);
        $dailyRecord = (int)($row['record'] ?? 0);

        // Fetch the user's daily goal so streak only counts days where goal was met
        $gStmt = $this->pdo->prepare("SELECT goal_pages_per_day FROM users WHERE id = :uid LIMIT 1");
        $gStmt->execute(['uid' => $userId]);
        $goal = max(1, (int)($gStmt->fetchColumn() ?: 20));

        // Only days where the user met their goal count towards streak
        $stmt = $this->pdo->prepare("
            SELECT day FROM reading_logs
            WHERE user_id = :uid AND pages >= :goal
            ORDER BY day DESC
        ");
        $stmt->execute(['uid' => $userId, 'goal' => $goal]);
        $days = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'day');

        return [
            'totalPages'     => $totalPages,
            'dailyRecord'    => $dailyRecord,
            'currentStreak'  => $this->currentStreak($days),
            'bestStreak'     => $this->bestStreak($days),
        ];
    }

    private function currentStreak(array $days): int
    {
        if (empty($days)) return 0;

        $today     = new \DateTime('today');
        $yesterday = (new \DateTime('today'))->modify('-1 day');
        $latest    = new \DateTime($days[0]);

        if ($latest < $yesterday) return 0;

        $streak   = 0;
        $expected = clone $latest;

        foreach ($days as $day) {
            $d    = new \DateTime($day);
            $diff = (int)$expected->diff($d)->days;
            if ($diff === 0) {
                $streak++;
                $expected->modify('-1 day');
            } else {
                break;
            }
        }

        return $streak;
    }

    private function bestStreak(array $days): int
    {
        if (empty($days)) return 0;

        $days    = array_reverse($days); // oldest → newest
        $best    = 1;
        $current = 1;

        for ($i = 1, $n = count($days); $i < $n; $i++) {
            $diff = (int)(new \DateTime($days[$i - 1]))->diff(new \DateTime($days[$i]))->days;
            if ($diff === 1) {
                $current++;
                $best = max($best, $current);
            } else {
                $current = 1;
            }
        }

        return $best;
    }

    // ── Library ───────────────────────────────────────────────────────────────

    private function libraryStats(int $userId): array
    {
        // Books by status
        $stmt = $this->pdo->prepare("
            SELECT ub.status, COUNT(*) AS cnt
            FROM user_books ub
            WHERE ub.user_id = :uid
            GROUP BY ub.status
        ");
        $stmt->execute(['uid' => $userId]);
        $booksRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $books = ['total' => 0, 'read' => 0, 'reading' => 0, 'toRead' => 0, 'abandoned' => 0, 'paused' => 0];
        foreach ($booksRows as $row) {
            $cnt = (int)$row['cnt'];
            $books['total'] += $cnt;
            match ($row['status']) {
                'Terminé'   => $books['read']      += $cnt,
                'En cours'  => $books['reading']   += $cnt,
                'À lire'    => $books['toRead']    += $cnt,
                'Abandonné' => $books['abandoned'] += $cnt,
                'En pause'  => $books['paused']    += $cnt,
                default     => null,
            };
        }

        // Top author (most books)
        $stmt = $this->pdo->prepare("
            SELECT b.author, COUNT(*) AS cnt
            FROM user_books ub
            JOIN books b ON b.id = ub.book_id
            WHERE ub.user_id = :uid AND b.author != ''
            GROUP BY b.author
            ORDER BY cnt DESC
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        $topAuthor = ($stmt->fetchColumn() ?: null);

        // Top genre
        $stmt = $this->pdo->prepare("
            SELECT b.genre, COUNT(*) AS cnt
            FROM user_books ub
            JOIN books b ON b.id = ub.book_id
            WHERE ub.user_id = :uid AND b.genre IS NOT NULL AND b.genre != ''
            GROUP BY b.genre
            ORDER BY cnt DESC
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        $topGenre = ($stmt->fetchColumn() ?: null);

        return [
            'books'     => $books,
            'topAuthor' => $topAuthor,
            'topGenre'  => $topGenre,
        ];
    }

    // ── Learning ─────────────────────────────────────────────────────────────

    private function learningStats(int $userId): array
    {
        // Global counts
        $stmt = $this->pdo->prepare("
            SELECT status, COUNT(*) AS cnt
            FROM learn_progress
            WHERE user_id = :uid
            GROUP BY status
        ");
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $mastered = 0;
        $learning = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'mastered') $mastered = (int)$row['cnt'];
            if ($row['status'] === 'learning') $learning = (int)$row['cnt'];
        }

        $countItem = function (string $table) use ($userId): int {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM {$table} t
                JOIN user_books ub ON ub.id = t.user_book_id
                WHERE ub.user_id = :uid
            ");
            $stmt->execute(['uid' => $userId]);
            return (int)$stmt->fetchColumn();
        };

        $totalVocab  = $countItem('vocab');
        $totalQuotes = $countItem('quotes');
        $totalChars  = $countItem('characters');
        $total       = $totalVocab + $totalQuotes + $totalChars;
        $new         = max(0, $total - $mastered - $learning);

        // Per-type breakdown
        $stmt = $this->pdo->prepare("
            SELECT item_type, status, COUNT(*) AS cnt
            FROM learn_progress
            WHERE user_id = :uid
            GROUP BY item_type, status
        ");
        $stmt->execute(['uid' => $userId]);
        $typeRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $byType = [
            'vocab'     => ['mastered' => 0, 'learning' => 0, 'total' => $totalVocab],
            'quote'     => ['mastered' => 0, 'learning' => 0, 'total' => $totalQuotes],
            'character' => ['mastered' => 0, 'learning' => 0, 'total' => $totalChars],
        ];
        foreach ($typeRows as $row) {
            $tp = $row['item_type'];
            $st = $row['status'];
            if (isset($byType[$tp]) && ($st === 'mastered' || $st === 'learning')) {
                $byType[$tp][$st] = (int)$row['cnt'];
            }
        }

        return [
            'mastered' => $mastered,
            'learning' => $learning,
            'new'      => $new,
            'total'    => $total,
            'byType'   => $byType,
        ];
    }
}
