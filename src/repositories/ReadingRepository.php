<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class ReadingRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    public function getGoalPagesPerDay(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT goal_pages_per_day FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $goal = $stmt->fetchColumn();

        return $goal !== false ? (int)$goal : 20;
    }

    public function setGoalPagesPerDay(int $userId, int $goal): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET goal_pages_per_day = :goal WHERE id = :id");
        $stmt->execute([
            ':goal' => $goal,
            ':id' => $userId,
        ]);
    }

    /**
     * Return entries as [{date:'YYYY-MM-DD', pages:int}]
     */
    public function getLogsInRange(int $userId, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare("
            SELECT day, pages
            FROM reading_logs
            WHERE user_id = :uid
              AND day BETWEEN :from AND :to
            ORDER BY day ASC
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':from' => $from,
            ':to' => $to,
        ]);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn($r) => [
            'date'  => (string)$r['day'],
            'pages' => (int)$r['pages'],
        ], $rows);
    }

    public function upsertLog(int $userId, string $day, int $pages): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO reading_logs (user_id, day, pages)
            VALUES (:uid, :day, :pages)
            ON DUPLICATE KEY UPDATE pages = VALUES(pages)
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':day' => $day,
            ':pages' => $pages,
        ]);
    }

    public function getLogByDay(int $userId, string $day): ?array
    {
        $sql = "SELECT day, pages FROM reading_logs WHERE user_id = :uid AND day = :day LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uid' => $userId, 'day' => $day]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Vérifie si on a déjà donné l'XP de "daily_goal_completed" pour ce jour.
     * meta = {"day":"YYYY-MM-DD", ...}
     */
    public function hasDailyGoalXp(int $userId, string $day): bool
    {
        $sql = "
            SELECT 1
            FROM xp_events
            WHERE user_id = :uid
              AND type = :type
              AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.day')) = :day
            LIMIT 1
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'uid' => $userId,
            'type' => 'daily_goal_completed',
            'day' => $day,
        ]);

        return (bool)$stmt->fetchColumn();
    }
}
