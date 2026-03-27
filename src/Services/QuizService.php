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

        $given = [];
        foreach ($answers as $a) {
            if (!is_array($a)) continue;
            $qid = (int)($a['questionId'] ?? 0);
            $aidRaw = $a['answerId'] ?? null;

            if ($qid <= 0) continue;

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

        $xpBase = (int)($meta['xp_base'] ?? 0);
        $xpPotential = (int)round($xpBase * max(0.2, $scorePct / 100));

        $attemptId = $this->attemptRepo->createAttempt($userId, $quizId, $total, $correct, $scorePct, $xpPotential);
        $this->attemptRepo->insertAnswers($attemptId, $rows);

        $xpAwarded = 0;
        $before = $this->progressService->snapshot($userId);
        $progress = $before;

        $cardService = new CardService();
        $newUnlockedCards = [];

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
                $newUnlockedCards = $this->mergeUnlockedCards(
                    $newUnlockedCards,
                    $progress['cardUnlock']['cards'] ?? []
                );
            } else {
                $progress = $this->progressService->snapshot($userId);
                $extraUnlocked = $cardService->checkUnlocks($userId);
                $newUnlockedCards = $this->mergeUnlockedCards($newUnlockedCards, $extraUnlocked);
            }
        } catch (\Throwable $e) {
            error_log('[BOOKLY][XP] award QUIZ_COMPLETED failed: ' . $e->getMessage());
            $progress = $this->progressService->snapshot($userId);
        }

        $cardRewarded = false;
        $cardId = isset($meta['card_reward_id']) ? (int)$meta['card_reward_id'] : 0;

        if ($cardId > 0 && $scorePct >= 70) {
            try {
                $rewardCard = $cardService->unlockById($userId, $cardId);
                if ($rewardCard) {
                    $cardRewarded = true;
                    $newUnlockedCards = $this->mergeUnlockedCards($newUnlockedCards, [$rewardCard]);
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][cards] card_reward unlock failed: ' . $e->getMessage());
            }
        }

        $levelUp = $progress['levelUp'] ?? $this->progressService->buildLevelUpPayload($before, $progress);

        $result = [
            'attemptId' => $attemptId,
            'total' => $total,
            'correct' => $correct,
            'scorePct' => $scorePct,
            'xpAwarded' => $xpAwarded,
            'reward' => [
                'cardRewarded' => $cardRewarded,
                'cardId' => $cardId > 0 ? $cardId : null,
            ],
            'progress' => $this->onlyProgressSnapshot($progress),
            'levelUp' => $levelUp,
            'cardUnlock' => [
                'happened' => count($newUnlockedCards) > 0,
                'cards' => array_values($newUnlockedCards),
            ],
            'awardedXp' => $xpAwarded,
            'awardType' => 'QUIZ_COMPLETED',
        ];

        $result['xp'] = $xpAwarded;
        $result['xpPotential'] = $xpPotential;
        $result['xpAwardedNow'] = ($xpAwarded > 0);
        $result['cardRewardUnlocked'] = $cardRewarded;
        $result['cardRewardId'] = $cardId > 0 ? $cardId : null;

        return $result;
    }

    private function mergeUnlockedCards(array $base, array $incoming): array
    {
        $map = [];
        foreach ($base as $card) {
            $id = (int)($card['id'] ?? 0);
            if ($id > 0) $map[$id] = $card;
        }
        foreach ($incoming as $card) {
            $id = (int)($card['id'] ?? 0);
            if ($id > 0) $map[$id] = $card;
        }
        return array_values($map);
    }

    private function onlyProgressSnapshot(array $progress): array
    {
        return [
            'xp' => (int)($progress['xp'] ?? 0),
            'level' => (int)($progress['level'] ?? 1),
            'title' => (string)($progress['title'] ?? 'Lecteur novice'),
            'progressPct' => (int)($progress['progressPct'] ?? 0),
            'xpToNext' => (int)($progress['xpToNext'] ?? 0),
            'levelXp' => (int)($progress['levelXp'] ?? 0),
            'levelXpSpan' => (int)($progress['levelXpSpan'] ?? 1),
        ];
    }
}