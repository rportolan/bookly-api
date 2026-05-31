<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class ChallengeRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    // -------------------------------------------------------------------------
    // Dashboard — active challenges (weekly + monthly)
    // -------------------------------------------------------------------------

    public function getActiveChallenges(int $userId): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $stmt = $this->pdo->prepare("
            SELECT
              c.id, c.kind, c.type, c.title, c.description,
              c.reward_title, c.reward_text,
              c.target_value, c.xp_reward, c.book_id,
              c.period_start, c.period_end, c.sort_order,
              b.title         AS book_title,
              ucc.id          AS completion_id,
              ucc.completed_at
            FROM challenges c
            LEFT JOIN books b ON b.id = c.book_id
            LEFT JOIN user_challenge_completions ucc
                   ON ucc.challenge_id = c.id AND ucc.user_id = :uid
            WHERE c.period_start <= :today AND c.period_end >= :today
            ORDER BY c.kind ASC, c.sort_order ASC, c.id ASC
        ");
        $stmt->execute(['uid' => $userId, 'today' => $today]);

        return $this->hydrateWithProgress($userId, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    // -------------------------------------------------------------------------
    // Dedicated page data — flat list, no period grouping shown to the user
    // -------------------------------------------------------------------------

    public function getPageData(int $userId): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        // Stats: total completed + total XP earned
        $stmtStats = $this->pdo->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(xp_awarded), 0) AS xp
            FROM user_challenge_completions WHERE user_id = :uid
        ");
        $stmtStats->execute(['uid' => $userId]);
        $stats = $stmtStats->fetch(\PDO::FETCH_ASSOC) ?: [];

        // All currently active challenges (within their period)
        $stmt = $this->pdo->prepare("
            SELECT
              c.id, c.kind, c.type, c.title, c.description,
              c.reward_title, c.reward_text,
              c.target_value, c.xp_reward, c.book_id,
              c.period_start, c.period_end, c.sort_order,
              b.title AS book_title,
              ucc.id  AS completion_id,
              ucc.completed_at
            FROM challenges c
            LEFT JOIN books b   ON b.id   = c.book_id
            LEFT JOIN user_challenge_completions ucc
                   ON ucc.challenge_id = c.id AND ucc.user_id = :uid
            WHERE c.period_start <= :today AND c.period_end >= :today
            ORDER BY ucc.id IS NULL DESC, c.sort_order ASC, c.id ASC
        ");
        $stmt->execute(['uid' => $userId, 'today' => $today]);
        $rows = $this->hydrateWithProgress($userId, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);

        return [
            'stats'     => [
                'totalCompleted' => (int)($stats['total'] ?? 0),
                'totalXp'        => (int)($stats['xp']    ?? 0),
            ],
            'active'    => array_values(array_filter($rows, fn($c) => !$c['completed'])),
            'completed' => array_values(array_filter($rows, fn($c) =>  $c['completed'])),
        ];
    }

    // -------------------------------------------------------------------------
    // Complete a challenge (award XP)
    // -------------------------------------------------------------------------

    public function complete(int $userId, int $challengeId, int $xpAwarded): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO user_challenge_completions (user_id, challenge_id, xp_awarded, seen)
            VALUES (:uid, :cid, :xp, 0)
        ");
        $stmt->execute(['uid' => $userId, 'cid' => $challengeId, 'xp' => $xpAwarded]);
        return $stmt->rowCount() > 0;
    }

    // -------------------------------------------------------------------------
    // Pop unseen completions — retourne les défis non encore vus et les marque
    // -------------------------------------------------------------------------

    public function popJustCompleted(int $userId): array
    {
        // Récupère les IDs non vus
        $stmt = $this->pdo->prepare("
            SELECT ucc.challenge_id
            FROM user_challenge_completions ucc
            WHERE ucc.user_id = :uid AND ucc.seen = 0
        ");
        $stmt->execute(['uid' => $userId]);
        $ids = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'challenge_id');

        if (empty($ids)) return [];

        // Marque comme vus immédiatement
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("
            UPDATE user_challenge_completions
            SET seen = 1
            WHERE user_id = ? AND challenge_id IN ($in)
        ")->execute([$userId, ...$ids]);

        // Retourne les défis complets avec reward_title / reward_text
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("
            SELECT
              c.id, c.kind, c.type, c.title, c.description,
              c.reward_title, c.reward_text,
              c.target_value, c.xp_reward, c.book_id,
              c.period_start, c.period_end, c.sort_order,
              b.title       AS book_title,
              ucc.id        AS completion_id,
              ucc.completed_at
            FROM challenges c
            LEFT JOIN books b ON b.id = c.book_id
            JOIN user_challenge_completions ucc
                 ON ucc.challenge_id = c.id AND ucc.user_id = ?
            WHERE c.id IN ($placeholders)
        ");
        $stmt->execute([$userId, ...$ids]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(fn($r) => $this->formatRow($r, (int)$r['target_value'], true), $rows);
    }


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function hydrateWithProgress(int $userId, array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $completed = $row['completion_id'] !== null;
            $progress  = $completed
                ? (int)$row['target_value']
                : $this->computeProgress(
                    $userId,
                    (string)$row['type'],
                    (string)$row['period_start'],
                    (string)$row['period_end'],
                    isset($row['book_id']) ? (int)$row['book_id'] : null
                );

            // Auto-complete check (handled by controller, but cap progress display)
            $result[] = $this->formatRow($row, $progress, $completed);
        }
        return $result;
    }

    private function formatRow(array $row, int $progress, bool $completed): array
    {
        return [
            'id'              => (int)$row['id'],
            'kind'            => (string)$row['kind'],
            'type'            => (string)$row['type'],
            'title'           => (string)$row['title'],
            'description'     => isset($row['description'])   ? (string)$row['description']   : null,
            'rewardTitle'     => isset($row['reward_title'])   ? (string)$row['reward_title']  : null,
            'rewardText'      => isset($row['reward_text'])    ? (string)$row['reward_text']   : null,
            'targetValue'     => (int)$row['target_value'],
            'xpReward'        => (int)$row['xp_reward'],
            'bookId'          => isset($row['book_id'])        ? (int)$row['book_id']          : null,
            'bookTitle'       => isset($row['book_title'])     ? (string)$row['book_title']    : null,
            'periodStart'     => (string)$row['period_start'],
            'periodEnd'       => (string)$row['period_end'],
            'currentProgress' => min($progress, (int)$row['target_value']),
            'completed'       => $completed,
            'completedAt'     => $row['completed_at'] ?? null,
        ];
    }

    private function computeProgress(
        int $userId,
        string $type,
        string $periodStart,
        string $periodEnd,
        ?int $bookId = null
    ): int {
        switch ($type) {
            case 'PAGES_READ':
                $stmt = $this->pdo->prepare("
                    SELECT COALESCE(SUM(pages), 0)
                    FROM reading_logs
                    WHERE user_id = :uid AND day >= :s AND day <= :e
                ");
                $stmt->execute(['uid' => $userId, 's' => $periodStart, 'e' => $periodEnd]);
                return (int)$stmt->fetchColumn();

            case 'VOCAB_ADDED':
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM vocab v
                    JOIN user_books ub ON ub.id = v.user_book_id
                    WHERE ub.user_id = :uid
                      AND DATE(v.created_at) BETWEEN :s AND :e
                ");
                $stmt->execute(['uid' => $userId, 's' => $periodStart, 'e' => $periodEnd]);
                return (int)$stmt->fetchColumn();

            case 'QUOTES_ADDED':
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM quotes q
                    JOIN user_books ub ON ub.id = q.user_book_id
                    WHERE ub.user_id = :uid
                      AND DATE(q.created_at) BETWEEN :s AND :e
                ");
                $stmt->execute(['uid' => $userId, 's' => $periodStart, 'e' => $periodEnd]);
                return (int)$stmt->fetchColumn();

            case 'CHAPTERS_ADDED':
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM chapters c
                    JOIN user_books ub ON ub.id = c.user_book_id
                    WHERE ub.user_id = :uid
                      AND DATE(c.created_at) BETWEEN :s AND :e
                ");
                $stmt->execute(['uid' => $userId, 's' => $periodStart, 'e' => $periodEnd]);
                return (int)$stmt->fetchColumn();

            case 'BOOKS_FINISHED':
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM user_books
                    WHERE user_id = :uid
                      AND status = 'Terminé'
                      AND DATE(updated_at) BETWEEN :s AND :e
                ");
                $stmt->execute(['uid' => $userId, 's' => $periodStart, 'e' => $periodEnd]);
                return (int)$stmt->fetchColumn();

            case 'BOOK_SPECIFIC':
                if ($bookId === null) return 0;
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM user_books
                    WHERE user_id = :uid
                      AND book_id  = :bid
                      AND status   = 'Terminé'
                ");
                $stmt->execute(['uid' => $userId, 'bid' => $bookId]);
                return (int)$stmt->fetchColumn() > 0 ? 1 : 0;

            case 'QUIZ_COMPLETED':
                // Tracked incrementally via user_challenge_completions events — returns 0 until hooked
                return 0;

            default:
                return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Legacy alias (backward compat with existing ChallengesController::index)
    // -------------------------------------------------------------------------

    public function getCurrentWeekChallenges(int $userId): array
    {
        return $this->getActiveChallenges($userId);
    }
}
