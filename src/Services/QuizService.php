<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\QuizRepository;
use App\Repositories\QuizAttemptRepository;
use App\Repositories\CardRepository;
use App\Repositories\ProgressRepository;

final class QuizService
{
    public function __construct(
        private QuizRepository $quizRepo = new QuizRepository(),
        private QuizAttemptRepository $attemptRepo = new QuizAttemptRepository(),
        private CardRepository $cardRepo = new CardRepository(),
        private ProgressRepository $progressRepo = new ProgressRepository(),
        private ProgressService $progressService = new ProgressService(),
    ) {}

    /**
     * @param array $answers [ {questionId, answerId}, ... ]
     */
    public function submitAttempt(int $userId, int $quizId, array $answers): array
    {
        $meta = $this->quizRepo->findMeta($quizId);
        if (!$meta) {
            throw new HttpException(404, 'QUIZ_NOT_FOUND', [], 'Quiz not found');
        }

        $correctMap = $this->quizRepo->getCorrectAnswerMap($quizId);
        if (!$correctMap) {
            throw new HttpException(422, 'QUIZ_INVALID', [], 'Quiz has no correct answers');
        }

        // Normalize client answers => map[questionId] = answerId|null
        $given = [];
        foreach ($answers as $a) {
            if (!is_array($a)) continue;
            $qid = (int)($a['questionId'] ?? 0);
            $aidRaw = $a['answerId'] ?? null;

            if ($qid <= 0) continue;

            // allow null
            if ($aidRaw === null || $aidRaw === 0 || $aidRaw === '0' || $aidRaw === '') {
                $given[$qid] = null;
            } else {
                $given[$qid] = (int)$aidRaw;
            }
        }

        $total = count($correctMap);
        $correct = 0;

        $rows = [];
        foreach ($correctMap as $questionId => $correctAnswerId) {
            $selected = $given[(int)$questionId] ?? null;

            $ok = ($selected !== null && (int)$selected === (int)$correctAnswerId) ? 1 : 0;
            $correct += $ok;

            $rows[] = [
                'question_id' => (int)$questionId,
                'selected_answer_id' => $selected,
                'is_correct' => $ok,
            ];
        }

        $scorePct = $total > 0 ? (int)round(($correct / $total) * 100) : 0;

        // XP potentiel calculé (ce que "vaudrait" la run)
        $xpBase = (int)($meta['xp_base'] ?? 0);
        $xpPotential = (int)round($xpBase * max(0.2, $scorePct / 100)); // min 20%

        // Persist attempt (on garde l’historique même si pas d’XP)
        $attemptId = $this->attemptRepo->createAttempt($userId, $quizId, $total, $correct, $scorePct, $xpPotential);
        $this->attemptRepo->insertAnswers($attemptId, $rows);

        // ✅ Award XP idempotent : une seule fois par quiz (via meta.quizId)
        $xpAwarded = 0;
        $progress = null;

        try {
            $already = $this->progressRepo->hasEventMeta($userId, 'QUIZ_COMPLETED', 'quizId', $quizId);

            if (!$already && $xpPotential > 0) {
                $progress = $this->progressService->award($userId, 'QUIZ_COMPLETED', $xpPotential, [
                    'quizId' => $quizId,
                    'attemptId' => $attemptId,
                    'scorePct' => $scorePct,
                    'title' => (string)($meta['title'] ?? ''),
                ]);
                $xpAwarded = $xpPotential;
            } else {
                // Pas d’XP cette fois, mais on renvoie quand même l’état actuel
                $progress = $this->progressService->snapshot($userId);
                // Et on check les unlocks (au cas où d’autres systèmes se basent sur des stats)
                (new CardService())->checkUnlocks($userId);
            }
        } catch (\Throwable $e) {
            error_log('[BOOKLY][XP] award QUIZ_COMPLETED failed: ' . $e->getMessage());
            // fallback: on renvoie au moins un snapshot pour que le front ne bug pas
            $progress = $this->progressService->snapshot($userId);
        }

        // 🎁 Optional card_reward_id (déblocage si score >= 70)
        $cardRewarded = false;
        $cardId = isset($meta['card_reward_id']) ? (int)$meta['card_reward_id'] : 0;

        if ($cardId > 0 && $scorePct >= 70) {
            try {
                if (!$this->cardRepo->userHas($userId, $cardId)) {
                    $this->cardRepo->unlock($userId, $cardId);
                    $cardRewarded = true;
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][cards] card_reward unlock failed: ' . $e->getMessage());
            }
        }

        // ✅ Payload aligné avec le front (QuizAttemptResult)
        $result = [
            'attemptId' => $attemptId,
            'total' => $total,
            'correct' => $correct,
            'scorePct' => $scorePct,
            'xpAwarded' => $xpAwarded, // ✅ ce que le front doit afficher
            'reward' => [
                'cardRewarded' => $cardRewarded,
                'cardId' => $cardId > 0 ? $cardId : null,
            ],
            'progress' => $progress,
        ];

        // 🧯 Rétro-compat (si tu as du front qui lit encore ces clés)
        $result['xp'] = $xpAwarded; // ancien: xp
        $result['xpPotential'] = $xpPotential; // utile debug UI si tu veux afficher "XP potentiel"
        $result['xpAwardedNow'] = ($xpAwarded > 0);
        $result['cardRewardUnlocked'] = $cardRewarded;
        $result['cardRewardId'] = $cardId > 0 ? $cardId : null;

        return $result;
    }
}