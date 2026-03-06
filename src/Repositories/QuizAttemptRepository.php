<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class QuizAttemptRepository
{
    public function createAttempt(int $userId, int $quizId, int $total, int $correct, int $scorePct, int $xpAwarded): int
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            INSERT INTO quiz_attempts (user_id, quiz_id, total_questions, correct_answers, score_pct, xp_awarded)
            VALUES (:uid, :qid, :total, :correct, :pct, :xp)
        ");
        $stmt->execute([
            'uid' => $userId,
            'qid' => $quizId,
            'total' => $total,
            'correct' => $correct,
            'pct' => $scorePct,
            'xp' => $xpAwarded,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public function insertAnswers(int $attemptId, array $rows): void
    {
        // rows: [ [question_id, selected_answer_id, is_correct], ... ]
        if (!$rows) return;

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            INSERT INTO quiz_attempt_answers (attempt_id, question_id, selected_answer_id, is_correct)
            VALUES (:aid, :qid, :sid, :ok)
        ");

        foreach ($rows as $r) {
            $stmt->execute([
                'aid' => $attemptId,
                'qid' => (int)$r['question_id'],
                'sid' => $r['selected_answer_id'] !== null ? (int)$r['selected_answer_id'] : null,
                'ok'  => (int)$r['is_correct'],
            ]);
        }
    }

    public function bestScorePct(int $userId, int $quizId): int
    {
        $stmt = Db::pdo()->prepare("
            SELECT MAX(score_pct) AS best
            FROM quiz_attempts
            WHERE user_id = :uid AND quiz_id = :qid
        ");
        $stmt->execute(['uid' => $userId, 'qid' => $quizId]);
        $row = $stmt->fetch();
        return (int)($row['best'] ?? 0);
    }
}