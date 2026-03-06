<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\HttpException;

use App\Repositories\QuizCategoryRepository;
use App\Repositories\QuizPackRepository;
use App\Repositories\QuizRepository;
use App\Services\QuizService;

final class QuizController
{
    public function __construct(
        private QuizCategoryRepository $catRepo = new QuizCategoryRepository(),
        private QuizPackRepository $packRepo = new QuizPackRepository(),
        private QuizRepository $quizRepo = new QuizRepository(),
        private QuizService $quizService = new QuizService(),
    ) {}

    /**
     * GET /v1/quiz/categories
     */
    public function categories(): void
    {
        Auth::requireAuth();
        $cats = $this->catRepo->list();
        Response::ok(['categories' => $cats]);
    }

    /**
     * GET /v1/quiz/packs?categoryId=123
     */
    public function packs(): void
    {
        Auth::requireAuth();

        $categoryId = (int)(Request::query('categoryId', '0') ?? '0');
        if ($categoryId < 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'categoryId'], 'Invalid categoryId');
        }

        $packs = $this->packRepo->listByCategory($categoryId > 0 ? $categoryId : null);
        Response::ok(['packs' => $packs]);
    }

    /**
     * GET /v1/quiz/packs/{id}
     */
    public function packShow(array $params): void
    {
        Auth::requireAuth();

        $packId = (int)($params['id'] ?? 0);
        if ($packId <= 0) {
            throw new HttpException(400, 'PACK_ID_REQUIRED', [], 'Pack id missing');
        }

        $pack = $this->packRepo->find($packId);
        if (!$pack) {
            throw new HttpException(404, 'PACK_NOT_FOUND', [], 'Pack not found');
        }

        $quizzes = $this->quizRepo->listByPack($packId);

        Response::ok([
            'pack' => $pack,
            'quizzes' => $quizzes
        ]);
    }

    /**
     * GET /v1/quiz/quizzes/{id}
     * Returns playable quiz (no correct answers).
     */
    public function quizShow(array $params): void
    {
        Auth::requireAuth();

        $quizId = (int)($params['id'] ?? 0);
        if ($quizId <= 0) {
            throw new HttpException(400, 'QUIZ_ID_REQUIRED', [], 'Quiz id missing');
        }

        $quiz = $this->quizRepo->getForPlay($quizId);
        if (!$quiz) {
            throw new HttpException(404, 'QUIZ_NOT_FOUND', [], 'Quiz not found');
        }

        Response::ok(['quiz' => $quiz]);
    }

    /**
     * POST /v1/quiz/quizzes/{id}/attempt
     * Body: { answers: [ { questionId, answerId } ] }
     */
    public function submit(array $params): void
    {
        $userId = Auth::requireAuth();

        $quizId = (int)($params['id'] ?? 0);
        if ($quizId <= 0) {
            throw new HttpException(400, 'QUIZ_ID_REQUIRED', [], 'Quiz id missing');
        }

        $body = Request::json();
        $answers = $body['answers'] ?? null;
        if (!is_array($answers)) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'answers'], 'Invalid answers');
        }

        // garde-fou
        if (count($answers) > 200) {
            $answers = array_slice($answers, 0, 200);
        }

        $result = $this->quizService->submitAttempt($userId, $quizId, $answers);
        Response::ok($result);
    }
}