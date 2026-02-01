<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\ChaptersRepository;

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
            // ton repo throw "Not Found" si user_book n'appartient pas au user
            throw new HttpException(404, 'NOT_FOUND', ['bookId' => $bookId], 'Not Found');
        }

        Response::created($this->mapRow($row));
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
