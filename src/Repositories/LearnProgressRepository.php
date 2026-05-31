<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class LearnProgressRepository
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::pdo();
    }

    /**
     * Bulk-update progress for a list of answered items.
     * Each result: [itemType, itemId, correct]
     * Status logic: 3 consecutive correct → mastered. Any wrong → learning, streak reset.
     */
    public function bulkUpdate(int $userId, array $results): int
    {
        if (empty($results)) return 0;

        $updated = 0;

        foreach ($results as $r) {
            $itemType = (string)($r['itemType'] ?? '');
            $itemId   = (int)($r['itemId'] ?? 0);
            $correct  = (bool)($r['correct'] ?? false);

            if (!in_array($itemType, ['vocab', 'quote', 'character'], true) || $itemId <= 0) {
                continue;
            }

            // Fetch existing progress for this item
            $stmt = $this->pdo->prepare("
                SELECT correct_streak, total_seen, status
                FROM learn_progress
                WHERE user_id = :uid AND item_type = :type AND item_id = :id
                LIMIT 1
            ");
            $stmt->execute(['uid' => $userId, 'type' => $itemType, 'id' => $itemId]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            $streak   = $existing ? (int)$existing['correct_streak'] : 0;
            $totalSeen = $existing ? (int)$existing['total_seen'] : 0;

            if ($correct) {
                $streak++;
            } else {
                $streak = 0;
            }

            $status = $streak >= 3 ? 'mastered' : 'learning';

            $upsert = $this->pdo->prepare("
                INSERT INTO learn_progress (user_id, item_type, item_id, correct_streak, total_seen, status, last_seen)
                VALUES (:uid, :type, :id, :streak, 1, :status, NOW())
                ON DUPLICATE KEY UPDATE
                    correct_streak = :streak,
                    total_seen     = total_seen + 1,
                    status         = :status,
                    last_seen      = NOW()
            ");
            $upsert->execute([
                'uid'    => $userId,
                'type'   => $itemType,
                'id'     => $itemId,
                'streak' => $streak,
                'status' => $status,
            ]);

            $updated++;
        }

        return $updated;
    }

    /**
     * Returns full stats: global counts, per-type breakdown, and library summary.
     */
    public function stats(int $userId): array
    {
        // Global counts per status — only for items whose source row still exists.
        // learn_progress has no FK to vocab/quotes/characters, so orphaned rows
        // (left after book deletion) must be excluded explicitly.
        $stmt = $this->pdo->prepare("
            SELECT lp.status, COUNT(*) AS cnt
            FROM learn_progress lp
            WHERE lp.user_id = :uid
              AND (
                (lp.item_type = 'vocab'     AND EXISTS (SELECT 1 FROM vocab       v WHERE v.id = lp.item_id))
                OR (lp.item_type = 'quote'  AND EXISTS (SELECT 1 FROM quotes      q WHERE q.id = lp.item_id))
                OR (lp.item_type = 'character' AND EXISTS (SELECT 1 FROM characters c WHERE c.id = lp.item_id))
              )
            GROUP BY lp.status
        ");
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $mastered = 0;
        $learning = 0;
        foreach ($rows as $row) {
            if ($row['status'] === 'mastered') $mastered = (int)$row['cnt'];
            if ($row['status'] === 'learning') $learning = (int)$row['cnt'];
        }

        $totalVocab  = $this->countItems($userId, 'vocab');
        $totalQuotes = $this->countItems($userId, 'quotes');
        $totalChars  = $this->countItems($userId, 'characters');
        $total       = $totalVocab + $totalQuotes + $totalChars;
        $new         = max(0, $total - $mastered - $learning);

        // Per-type breakdown — same orphan-safe filter
        $typeStmt = $this->pdo->prepare("
            SELECT lp.item_type, lp.status, COUNT(*) AS cnt
            FROM learn_progress lp
            WHERE lp.user_id = :uid
              AND (
                (lp.item_type = 'vocab'     AND EXISTS (SELECT 1 FROM vocab       v WHERE v.id = lp.item_id))
                OR (lp.item_type = 'quote'  AND EXISTS (SELECT 1 FROM quotes      q WHERE q.id = lp.item_id))
                OR (lp.item_type = 'character' AND EXISTS (SELECT 1 FROM characters c WHERE c.id = lp.item_id))
              )
            GROUP BY lp.item_type, lp.status
        ");
        $typeStmt->execute(['uid' => $userId]);
        $typeRows = $typeStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $byType = [
            'vocab'     => ['mastered' => 0, 'learning' => 0, 'total' => $totalVocab],
            'quote'     => ['mastered' => 0, 'learning' => 0, 'total' => $totalQuotes],
            'character' => ['mastered' => 0, 'learning' => 0, 'total' => $totalChars],
        ];
        foreach ($typeRows as $row) {
            $type = $row['item_type'];
            $st   = $row['status'];
            if (isset($byType[$type]) && ($st === 'mastered' || $st === 'learning')) {
                $byType[$type][$st] = (int)$row['cnt'];
            }
        }

        // Books stats
        $booksStmt = $this->pdo->prepare("
            SELECT status, COUNT(*) as cnt
            FROM user_books
            WHERE user_id = :uid
            GROUP BY status
        ");
        $booksStmt->execute(['uid' => $userId]);
        $booksRows = $booksStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

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

        return [
            'mastered' => $mastered,
            'learning' => $learning,
            'new'      => $new,
            'total'    => $total,
            'byType'   => $byType,
            'books'    => $books,
        ];
    }

    private function countItems(int $userId, string $table): int
    {
        $allowed = ['vocab', 'quotes', 'characters'];
        if (!in_array($table, $allowed, true)) return 0;

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM {$table} t
            JOIN user_books ub ON ub.id = t.user_book_id
            WHERE ub.user_id = :uid
        ");
        $stmt->execute(['uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }
}
