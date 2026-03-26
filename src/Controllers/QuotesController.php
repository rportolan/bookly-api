<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\QuoteRepository;
use App\Services\ProgressService;

final class QuotesController
{
    public function index(array $params): void
    {
        $userId = Auth::requireAuth();

        $userBookId = (int)($params['id'] ?? 0);
        if ($userBookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }

        $repo = new QuoteRepository();
        $rows = $repo->listForBook($userId, $userBookId);

        Response::ok(array_map([$this, 'mapRow'], $rows));
    }

    public function store(array $params): void
    {
        $userId = Auth::requireAuth();

        $userBookId = (int)($params['id'] ?? 0);
        if ($userBookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }

        $body = Request::json();
        $content = trim((string)($body['content'] ?? ''));
        $author = isset($body['author']) ? trim((string)$body['author']) : null;
        if ($author === '') $author = null;

        if ($content === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'content'], 'content is required');
        }

        if ($author !== null && mb_strlen($author) > 255) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'author'], 'author is too long');
        }

        $repo = new QuoteRepository();

        try {
            $row = $repo->create($userId, $userBookId, $content, $author);
        } catch (\RuntimeException $e) {
            throw new HttpException(404, 'NOT_FOUND', ['userBookId' => $userBookId], 'Book not found');
        }

        $progressService = new ProgressService();
        $progress = $progressService->snapshot($userId);

        try {
            $progress = $progressService->award($userId, 'QUOTE_CREATED', 3, [
                'quoteId' => (int)($row['id'] ?? 0),
                'userBookId' => $userBookId,
            ]);
        } catch (\Throwable $e) {
            error_log('[BOOKLY][XP] award QUOTE_CREATED failed: ' . $e->getMessage());
        }

        Response::created([
            ...$this->mapRow($row),
            'progress' => $this->onlyProgressSnapshot($progress),
            'levelUp' => $progress['levelUp'] ?? $progressService->buildLevelUpPayload(
                $progressService->snapshot($userId),
                $progressService->snapshot($userId)
            ),
        ]);
    }

    public function update(array $params): void
    {
        $userId = Auth::requireAuth();

        $userBookId = (int)($params['id'] ?? 0);
        $quoteId = (int)($params['quoteId'] ?? 0);

        if ($userBookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }
        if ($quoteId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'quoteId'], 'Invalid quote id');
        }

        $body = Request::json();
        $content = trim((string)($body['content'] ?? ''));
        $author = isset($body['author']) ? trim((string)$body['author']) : null;
        if ($author === '') $author = null;

        if ($content === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'content'], 'content is required');
        }
        if ($author !== null && mb_strlen($author) > 255) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'author'], 'author is too long');
        }

        $repo = new QuoteRepository();
        $row = $repo->update($userId, $userBookId, $quoteId, $content, $author);

        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', [
                'userBookId' => $userBookId,
                'quoteId' => $quoteId,
            ], 'Quote not found');
        }

        Response::ok($this->mapRow($row));
    }

    public function destroy(array $params): void
    {
        $userId = Auth::requireAuth();

        $userBookId = (int)($params['id'] ?? 0);
        $quoteId = (int)($params['quoteId'] ?? 0);

        if ($userBookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }
        if ($quoteId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'quoteId'], 'Invalid quote id');
        }

        $repo = new QuoteRepository();
        $ok = $repo->delete($userId, $userBookId, $quoteId);

        if (!$ok) {
            throw new HttpException(404, 'NOT_FOUND', [
                'userBookId' => $userBookId,
                'quoteId' => $quoteId,
            ], 'Quote not found');
        }

        Response::ok(['deleted' => true]);
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

    private function mapRow(array $r): array
    {
        $author = $r['author'] ?? null;
        $author = is_string($author) ? trim($author) : null;
        if ($author === '') $author = null;

        return [
            'id' => (int)($r['id'] ?? 0),
            'content' => (string)($r['content'] ?? ''),
            'author' => $author,
            'createdAt' => $r['created_at'] ?? null,
        ];
    }
}