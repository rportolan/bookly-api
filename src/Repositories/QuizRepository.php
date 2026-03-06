<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class QuizRepository
{
    public function listByPack(int $packId): array
    {
        $stmt = Db::pdo()->prepare("
            SELECT id, pack_id, title, difficulty, xp_base, card_reward_id, sort_order, is_active
            FROM quizzes
            WHERE pack_id = :pid AND is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute(['pid' => $packId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Returns quiz + questions + answers WITHOUT is_correct (safe for client).
     */
    public function getForPlay(int $quizId): ?array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT id, pack_id, title, difficulty, xp_base, card_reward_id
            FROM quizzes
            WHERE id = :id AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['id' => $quizId]);
        $quiz = $stmt->fetch();
        if (!$quiz) return null;

        $qStmt = $pdo->prepare("
            SELECT id, question_text, explanation, sort_order
            FROM quiz_questions
            WHERE quiz_id = :qid
            ORDER BY sort_order ASC, id ASC
        ");
        $qStmt->execute(['qid' => $quizId]);
        $questions = $qStmt->fetchAll() ?: [];

        if (!$questions) {
            $quiz['questions'] = [];
            return $quiz;
        }

        $questionIds = array_map(fn($q) => (int)$q['id'], $questions);
        $in = implode(',', array_fill(0, count($questionIds), '?'));

        $aStmt = $pdo->prepare("
            SELECT id, question_id, answer_text, sort_order
            FROM quiz_answers
            WHERE question_id IN ($in)
            ORDER BY question_id ASC, sort_order ASC, id ASC
        ");
        $aStmt->execute($questionIds);
        $answers = $aStmt->fetchAll() ?: [];

        $answersByQ = [];
        foreach ($answers as $a) {
            $qid = (int)$a['question_id'];
            $answersByQ[$qid] ??= [];
            $answersByQ[$qid][] = [
                'id' => (int)$a['id'],
                'text' => (string)$a['answer_text'],
                'order' => (int)$a['sort_order'],
            ];
        }

        $quiz['questions'] = array_map(function($q) use ($answersByQ) {
            $qid = (int)$q['id'];
            return [
                'id' => $qid,
                'text' => (string)$q['question_text'],
                'explanation' => $q['explanation'] ?? null, // tu peux choisir de ne pas l'envoyer si tu préfères
                'order' => (int)$q['sort_order'],
                'answers' => $answersByQ[$qid] ?? [],
            ];
        }, $questions);

        return $quiz;
    }

    /**
     * Returns [questionId => correctAnswerId]
     */
    public function getCorrectAnswerMap(int $quizId): array
    {
        $stmt = Db::pdo()->prepare("
            SELECT q.id AS question_id, a.id AS answer_id
            FROM quiz_questions q
            JOIN quiz_answers a ON a.question_id = q.id AND a.is_correct = 1
            WHERE q.quiz_id = :qid
        ");
        $stmt->execute(['qid' => $quizId]);
        $rows = $stmt->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['question_id']] = (int)$r['answer_id'];
        }
        return $map;
    }

    public function findMeta(int $quizId): ?array
    {
        $stmt = Db::pdo()->prepare("
            SELECT id, title, xp_base, card_reward_id
            FROM quizzes
            WHERE id = :id AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['id' => $quizId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}