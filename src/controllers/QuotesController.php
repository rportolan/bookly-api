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

        if ($content === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'content'], 'content is required');
        }

        $repo = new QuoteRepository();

        try {
            $row = $repo->create($userId, $userBookId, $content);
        } catch (\RuntimeException $e) {
            // ton repo throw "Not Found" quand le user_book n'appartient pas à l'user
            throw new HttpException(404, 'NOT_FOUND', ['userBookId' => $userBookId], 'Book not found');
        }

        // XP (MVP) : on log si ça fail (mieux que silent)
        try {
            $progress = new ProgressService();
            $progress->award($userId, 'QUOTE_CREATED', 3, [
                'quoteId' => (int)($row['id'] ?? 0),
                'userBookId' => $userBookId,
            ]);
        } catch (\Throwable $e) {
            error_log('[BOOKLY][XP] award QUOTE_CREATED failed: ' . $e->getMessage());
        }

        Response::created($this->mapRow($row));
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

        if ($content === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'content'], 'content is required');
        }

        $repo = new QuoteRepository();
        $row = $repo->update($userId, $userBookId, $quoteId, $content);

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
        // (option) Response::noContent(); si tu préfères 204
    }

    private function mapRow(array $r): array
    {
        return [
            'id' => (int)($r['id'] ?? 0),
            'content' => (string)($r['content'] ?? ''),
            'createdAt' => $r['created_at'] ?? null,
        ];
    }
}
