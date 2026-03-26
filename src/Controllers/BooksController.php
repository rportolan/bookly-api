<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\BookRepository;
use App\Repositories\ProgressRepository;
use App\Services\ProgressService;

final class BooksController
{
    public function index(): void
    {
        $userId = Auth::requireAuth();

        $q = Request::query('q', '');
        $status = Request::query('status', 'all');

        $repo = new BookRepository();
        $rows = $repo->listForUser($userId, $q, $status);

        Response::ok(array_map([$this, 'mapRow'], $rows));
    }

    public function show(array $params): void
    {
        $userId = Auth::requireAuth();

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid id');
        }

        $repo = new BookRepository();
        $row = $repo->findOneForUser($userId, $id);

        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', ['id' => $id], 'Not Found');
        }

        Response::ok($this->mapRow($row));
    }

    public function store(): void
    {
        $userId = Auth::requireAuth();
        $body = Request::json();

        $title = trim((string)($body['title'] ?? ''));
        $author = trim((string)($body['author'] ?? ''));

        if ($title === '' || $author === '') {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['required' => ['title', 'author']],
                'Missing required fields'
            );
        }

        $repo = new BookRepository();
        $row = $repo->createForUser($userId, $body);

        $progressService = new ProgressService();
        $progress = $progressService->snapshot($userId);

        try {
            $progress = $progressService->award($userId, 'BOOK_CREATED', 0, [
                'userBookId' => (int)($row['id'] ?? 0),
                'bookId' => (int)($row['bookId'] ?? 0),
                'title' => (string)($row['title'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            error_log('[BOOKLY][XP] award BOOK_CREATED failed: ' . $e->getMessage());
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

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid id');
        }

        $body = Request::json();
        $repo = new BookRepository();

        $before = $repo->findOneForUser($userId, $id);
        if (!$before) {
            throw new HttpException(404, 'NOT_FOUND', ['id' => $id], 'Not Found');
        }

        $row = $repo->updateForUser($userId, $id, $body);
        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', ['id' => $id], 'Not Found');
        }

        $progressService = new ProgressService();
        $beforeProgress = $progressService->snapshot($userId);
        $afterProgress = $beforeProgress;

        try {
            $beforeAnalysis = trim((string)($before['analysis_work'] ?? ''));
            $afterAnalysis = trim((string)($row['analysis_work'] ?? ''));

            $becameNonEmpty = ($beforeAnalysis === '' && $afterAnalysis !== '');
            if ($becameNonEmpty) {
                $pr = new ProgressRepository();
                $already = $pr->hasEventMeta($userId, 'ANALYSIS_ADDED', 'userBookId', (int)$id);

                if (!$already) {
                    $afterProgress = $progressService->award($userId, 'ANALYSIS_ADDED', 0, [
                        'userBookId' => (int)$id,
                        'bookId' => (int)($row['bookId'] ?? 0),
                        'title' => (string)($row['title'] ?? ''),
                    ]);
                } else {
                    $afterProgress = $progressService->snapshot($userId);
                }
            } else {
                $afterProgress = $progressService->snapshot($userId);
            }
        } catch (\Throwable $e) {
            error_log('[BOOKLY][XP] award ANALYSIS_ADDED failed: ' . $e->getMessage());
            $afterProgress = $progressService->snapshot($userId);
        }

        $levelUp = $afterProgress['levelUp'] ?? $progressService->buildLevelUpPayload($beforeProgress, $afterProgress);

        Response::ok([
            ...$this->mapRow($row),
            'progress' => $this->onlyProgressSnapshot($afterProgress),
            'levelUp' => $levelUp,
        ]);
    }

    public function destroy(array $params): void
    {
        $userId = Auth::requireAuth();

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid id');
        }

        $repo = new BookRepository();
        $ok = $repo->deleteForUser($userId, $id);

        if (!$ok) {
            throw new HttpException(404, 'NOT_FOUND', ['id' => $id], 'Not Found');
        }

        Response::ok(['deleted' => true]);
    }

    public function updateProgress(array $params): void
    {
        $userId = Auth::requireAuth();

        $userBookId = (int)($params['id'] ?? 0);
        if ($userBookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }

        $body = Request::json();

        if (!array_key_exists('progressPages', $body) || !array_key_exists('status', $body)) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['required' => ['progressPages', 'status']],
                'Missing required fields'
            );
        }

        if (!is_numeric($body['progressPages'])) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'progressPages'], 'progressPages must be a number');
        }

        $progressPages = (int)$body['progressPages'];
        $status = trim((string)$body['status']);

        $allowed = ['À lire', 'En cours', 'Terminé', 'Abandonné', 'En pause'];
        if (!in_array($status, $allowed, true)) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'status', 'allowed' => $allowed],
                'Invalid status'
            );
        }

        $repo = new BookRepository();

        $before = $repo->findOneForUser($userId, $userBookId);
        if (!$before) {
            throw new HttpException(404, 'NOT_FOUND', ['id' => $userBookId], 'Not Found');
        }

        $progressService = new ProgressService();
        $beforeProgress = $progressService->snapshot($userId);

        $updated = $repo->updateProgress($userId, $userBookId, $progressPages, $status);

        if (!$updated) {
            throw new HttpException(404, 'NOT_FOUND', ['id' => $userBookId], 'Not Found');
        }

        $afterProgress = $beforeProgress;

        try {
            $beforeStatus = (string)($before['status'] ?? '');
            $afterStatus = (string)($updated['status'] ?? '');

            $justFinished = ($beforeStatus !== 'Terminé' && $afterStatus === 'Terminé');

            if ($justFinished) {
                $pr = new ProgressRepository();
                $already = $pr->hasEventMeta($userId, 'BOOK_DONE', 'userBookId', $userBookId);

                if (!$already) {
                    $afterProgress = $progressService->award($userId, 'BOOK_DONE', 0, [
                        'userBookId' => $userBookId,
                        'bookId' => (int)($updated['bookId'] ?? 0),
                        'title' => (string)($updated['title'] ?? ''),
                    ]);
                } else {
                    $afterProgress = $progressService->snapshot($userId);
                }
            } else {
                $afterProgress = $progressService->snapshot($userId);
            }
        } catch (\Throwable $e) {
            error_log('[BOOKLY][XP] award BOOK_DONE failed: ' . $e->getMessage());
            $afterProgress = $progressService->snapshot($userId);
        }

        $levelUp = $afterProgress['levelUp'] ?? $progressService->buildLevelUpPayload($beforeProgress, $afterProgress);

        Response::ok([
            ...$this->mapRow($updated),
            'progress' => $this->onlyProgressSnapshot($afterProgress),
            'levelUp' => $levelUp,
        ]);
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
        return [
            'id' => (int)$r['id'],
            'bookId' => (int)($r['bookId'] ?? 0),
            'title' => (string)($r['title'] ?? ''),
            'author' => (string)($r['author'] ?? ''),
            'genre' => $r['genre'] ?? null,
            'pages' => (int)($r['pages'] ?? 0),
            'progressPages' => (int)($r['progress_pages'] ?? 0),
            'status' => (string)($r['status'] ?? 'À lire'),
            'startedAt' => $r['started_at'] ?? null,
            'finishedAt' => $r['finished_at'] ?? null,
            'publisher' => $r['publisher'] ?? null,
            'rating' => isset($r['rating']) && $r['rating'] !== null ? (float)$r['rating'] : null,
            'summary' => $r['summary'] ?? null,
            'analysisWork' => $r['analysis_work'] ?? null,
            'coverUrl' => $r['cover_url'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }
}