<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ChaptersRepository;
use App\Services\ProgressService;

final class ChaptersController
{
    public function index(array $params): void
    {
        $userId = Auth::requireAuth();

        $bookId = (int)($params['id'] ?? 0);
        if ($bookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }

        $repo = new ChaptersRepository();
        $rows = $repo->listForBook($userId, $bookId);

        Response::ok(array_map([$this, 'mapRow'], $rows));
    }

    public function store(array $params): void
    {
        $userId = Auth::requireAuth();

        $bookId = (int)($params['id'] ?? 0);
        if ($bookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }

        $body = Request::json();

        $title = trim((string)($body['title'] ?? ''));
        $resume = array_key_exists('resume', $body) ? (is_null($body['resume']) ? null : trim((string)$body['resume'])) : null;
        $observation = array_key_exists('observation', $body) ? (is_null($body['observation']) ? null : trim((string)$body['observation'])) : null;
        $position = array_key_exists('position', $body)
            ? (($body['position'] === null || $body['position'] === '') ? null : (int)$body['position'])
            : null;

        if ($title === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'title'], 'Title is required');
        }

        $repo = new ChaptersRepository();

        try {
            $row = $repo->create($userId, $bookId, $title, $resume, $observation, $position);
        } catch (\RuntimeException $e) {
            throw new HttpException(404, 'NOT_FOUND', ['bookId' => $bookId], 'Not Found');
        }

        $progressService = new ProgressService();
        $progress = $progressService->snapshot($userId);

        try {
            $progress = $progressService->award($userId, 'CHAPTER_ADDED', 0, [
                'userBookId' => $bookId,
                'chapterId' => (int)($row['id'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            error_log('[BOOKLY][XP] award CHAPTER_ADDED failed: ' . $e->getMessage());
        }

        Response::created([
            ...$this->mapRow($row),
            'progress' => $this->onlyProgressSnapshot($progress),
            'levelUp' => $progress['levelUp'] ?? $progressService->buildLevelUpPayload(
                $progressService->snapshot($userId),
                $progressService->snapshot($userId)
            ),
            'cardUnlock' => $progress['cardUnlock'] ?? $this->emptyCardUnlock(),
            'awardedXp' => (int)($progress['awardedXp'] ?? 0),
            'awardType' => (string)($progress['awardType'] ?? 'CHAPTER_ADDED'),
        ]);
    }

    public function update(array $params): void
    {
        $userId = Auth::requireAuth();

        $bookId = (int)($params['id'] ?? 0);
        $chapterId = (int)($params['chapterId'] ?? 0);

        if ($bookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }
        if ($chapterId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'chapterId'], 'Invalid chapter id');
        }

        $body = Request::json();

        $patch = [];
        if (array_key_exists('title', $body)) $patch['title'] = trim((string)$body['title']);
        if (array_key_exists('resume', $body)) $patch['resume'] = ($body['resume'] === null) ? null : trim((string)$body['resume']);
        if (array_key_exists('observation', $body)) $patch['observation'] = ($body['observation'] === null) ? null : trim((string)$body['observation']);
        if (array_key_exists('position', $body)) $patch['position'] = ($body['position'] === null || $body['position'] === '') ? null : (int)$body['position'];

        if (array_key_exists('title', $patch) && $patch['title'] === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'title'], 'Invalid title');
        }

        $repo = new ChaptersRepository();
        $row = $repo->update($userId, $bookId, $chapterId, $patch);

        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', ['chapterId' => $chapterId], 'Not Found');
        }

        Response::ok($this->mapRow($row));
    }

    public function destroy(array $params): void
    {
        $userId = Auth::requireAuth();

        $bookId = (int)($params['id'] ?? 0);
        $chapterId = (int)($params['chapterId'] ?? 0);

        if ($bookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }
        if ($chapterId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'chapterId'], 'Invalid chapter id');
        }

        $repo = new ChaptersRepository();
        $ok = $repo->delete($userId, $bookId, $chapterId);

        if (!$ok) {
            throw new HttpException(404, 'NOT_FOUND', ['chapterId' => $chapterId], 'Not Found');
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

    private function emptyCardUnlock(): array
    {
        return [
            'happened' => false,
            'cards' => [],
        ];
    }

    private function mapRow(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'title' => $r['title'],
            'resume' => $r['resume'],
            'observation' => $r['observation'],
            'position' => $r['position'] !== null ? (int)$r['position'] : null,
            'createdAt' => $r['created_at'],
            'updatedAt' => $r['updated_at'],
        ];
    }
}