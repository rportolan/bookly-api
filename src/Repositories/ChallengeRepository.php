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
    // Dashboard — tous les défis actifs (sans contrainte de période)
    // -------------------------------------------------------------------------

    public function getActiveChallenges(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
              c.id, c.type, c.title, c.description,
              c.reward_title, c.reward_text,
              c.target_value, c.xp_reward, c.book_id,
              c.sort_order,
              b.title         AS book_title,
              ucc.id          AS completion_id,
              ucc.completed_at
            FROM challenges c
            LEFT JOIN books b ON b.id = c.book_id
            LEFT JOIN user_challenge_completions ucc
                   ON ucc.challenge_id = c.id AND ucc.user_id = :uid
            ORDER BY c.sort_order ASC, c.id ASC
        ");
        $stmt->execute(['uid' => $userId]);

        return $this->hydrateWithProgress($userId, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    // -------------------------------------------------------------------------
    // Page dédiée aux défis
    // -------------------------------------------------------------------------

    public function getPageData(int $userId): array
    {
        // Stats globales
        $stmtStats = $this->pdo->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM(xp_awarded), 0) AS xp
            FROM user_challenge_completions WHERE user_id = :uid
        ");
        $stmtStats->execute(['uid' => $userId]);
        $stats = $stmtStats->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Tous les défis sans filtre de période
        $stmt = $this->pdo->prepare("
            SELECT
              c.id, c.type, c.title, c.description,
              c.reward_title, c.reward_text,
              c.target_value, c.xp_reward, c.book_id,
              c.sort_order,
              b.title AS book_title,
              ucc.id  AS completion_id,
              ucc.completed_at
            FROM challenges c
            LEFT JOIN books b   ON b.id   = c.book_id
            LEFT JOIN user_challenge_completions ucc
                   ON ucc.challenge_id = c.id AND ucc.user_id = :uid
            ORDER BY ucc.id IS NULL DESC, c.sort_order ASC, c.id ASC
        ");
        $stmt->execute(['uid' => $userId]);
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
    // Compléter un défi
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
    // Pop unseen completions
    // -------------------------------------------------------------------------

    public function popJustCompleted(int $userId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT ucc.challenge_id
            FROM user_challenge_completions ucc
            WHERE ucc.user_id = :uid AND ucc.seen = 0
        ");
        $stmt->execute(['uid' => $userId]);
        $ids = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'challenge_id');

        if (empty($ids)) return [];

        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("
            UPDATE user_challenge_completions
            SET seen = 1
            WHERE user_id = ? AND challenge_id IN ($in)
        ")->execute([$userId, ...$ids]);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("
            SELECT
              c.id, c.type, c.title, c.description,
              c.reward_title, c.reward_text,
              c.target_value, c.xp_reward, c.book_id,
              c.sort_order,
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
                    isset($row['book_id']) ? (int)$row['book_id'] : null
                );

            $result[] = $this->formatRow($row, $progress, $completed);
        }
        return $result;
    }

    private function formatRow(array $row, int $progress, bool $completed): array
    {
        return [
            'id'              => (int)$row['id'],
            'type'            => (string)$row['type'],
            'title'           => (string)$row['title'],
            'description'     => isset($row['description'])  ? (string)$row['description']  : null,
            'rewardTitle'     => isset($row['reward_title'])  ? (string)$row['reward_title'] : null,
            'rewardText'      => isset($row['reward_text'])   ? (string)$row['reward_text']  : null,
            'targetValue'     => (int)$row['target_value'],
            'xpReward'        => (int)$row['xp_reward'],
            'bookId'          => isset($row['book_id'])       ? (int)$row['book_id']         : null,
            'bookTitle'       => isset($row['book_title'])    ? (string)$row['book_title']   : null,
            'currentProgress' => min($progress, (int)$row['target_value']),
            'completed'       => $completed,
            'completedAt'     => $row['completed_at'] ?? null,
        ];
    }

    /**
     * Calcule la progression sur toute la durée (sans contrainte de période).
     */
    private function computeProgress(int $userId, string $type, ?int $bookId = null): int
    {
        switch ($type) {
            case 'PAGES_READ':
                $stmt = $this->pdo->prepare("
                    SELECT COALESCE(SUM(pages), 0)
                    FROM reading_logs
                    WHERE user_id = :uid
                ");
                $stmt->execute(['uid' => $userId]);
                return (int)$stmt->fetchColumn();

            case 'VOCAB_ADDED':
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM vocab v
                    JOIN user_books ub ON ub.id = v.user_book_id
                    WHERE ub.user_id = :uid
                ");
                $stmt->execute(['uid' => $userId]);
                return (int)$stmt->fetchColumn();

            case 'QUOTES_ADDED':
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM quotes q
                    JOIN user_books ub ON ub.id = q.user_book_id
                    WHERE ub.user_id = :uid
                ");
                $stmt->execute(['uid' => $userId]);
                return (int)$stmt->fetchColumn();

            case 'CHAPTERS_ADDED':
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM chapters c
                    JOIN user_books ub ON ub.id = c.user_book_id
                    WHERE ub.user_id = :uid
                ");
                $stmt->execute(['uid' => $userId]);
                return (int)$stmt->fetchColumn();

            case 'BOOKS_FINISHED':
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM user_books
                    WHERE user_id = :uid AND status = 'Terminé'
                ");
                $stmt->execute(['uid' => $userId]);
                return (int)$stmt->fetchColumn();

            case 'BOOK_SPECIFIC':
                if ($bookId === null) return 0;
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*) FROM user_books
                    WHERE user_id = :uid AND book_id = :bid AND status = 'Terminé'
                ");
                $stmt->execute(['uid' => $userId, 'bid' => $bookId]);
                return (int)$stmt->fetchColumn() > 0 ? 1 : 0;

            default:
                return 0;
        }
    }

    // Alias backward compat
    public function getCurrentWeekChallenges(int $userId): array
    {
        return $this->getActiveChallenges($userId);
    }
}
