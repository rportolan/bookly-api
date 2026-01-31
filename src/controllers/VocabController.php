<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\VocabRepository;
use App\Services\ProgressService;

final class VocabController
{
    public function index(array $params): void
    {
        $userId = Auth::requireAuth();

        $userBookId = (int)($params['id'] ?? 0);
        if ($userBookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }

        $repo = new VocabRepository();
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
        $word = trim((string)($body['word'] ?? ''));
        $definition = trim((string)($body['definition'] ?? ''));

        if ($word === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'word'], 'word is required');
        }
        if ($definition === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'definition'], 'definition is required');
        }

        $repo = new VocabRepository();

        try {
            $row = $repo->create($userId, $userBookId, $word, $definition);
        } catch (\PDOException $e) {
            // Unique constraint (si tu l'ajoutes plus tard) => 1062
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'CONFLICT', [
                    'field' => 'word',
                    'reason' => 'duplicate',
                ], 'This word already exists for this book');
            }
            throw $e;
        } catch (\RuntimeException $e) {
            throw new HttpException(404, 'NOT_FOUND', ['userBookId' => $userBookId], 'Book not found');
        }

        // XP (MVP)
        try {
            $progress = new ProgressService();
            $progress->award($userId, 'VOCAB_CREATED', 2, [
                'vocabId' => (int)($row['id'] ?? 0),
                'userBookId' => $userBookId,
                'word' => $word,
            ]);
        } catch (\Throwable $e) {
            error_log('[BOOKLY][XP] award VOCAB_CREATED failed: ' . $e->getMessage());
        }

        Response::created($this->mapRow($row));
    }

    public function update(array $params): void
    {
        $userId = Auth::requireAuth();

        $userBookId = (int)($params['id'] ?? 0);
        $vocabId = (int)($params['vocabId'] ?? 0);

        if ($userBookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }
        if ($vocabId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'vocabId'], 'Invalid vocab id');
        }

        $body = Request::json();

        $patch = [];
        if (array_key_exists('word', $body)) {
            $patch['word'] = trim((string)$body['word']);
            if ($patch['word'] === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'word'], 'word cannot be empty');
            }
        }
        if (array_key_exists('definition', $body)) {
            $patch['definition'] = trim((string)$body['definition']);
            if ($patch['definition'] === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'definition'], 'definition cannot be empty');
            }
        }

        if (!$patch) {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'allowed' => ['word', 'definition'],
            ], 'No fields to update');
        }

        $repo = new VocabRepository();

        try {
            $row = $repo->update($userId, $userBookId, $vocabId, $patch);
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'CONFLICT', [
                    'field' => 'word',
                    'reason' => 'duplicate',
                ], 'This word already exists for this book');
            }
            throw $e;
        }

        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', [
                'userBookId' => $userBookId,
                'vocabId' => $vocabId,
            ], 'Vocab not found');
        }

        Response::ok($this->mapRow($row));
    }

    public function destroy(array $params): void
    {
        $userId = Auth::requireAuth();

        $userBookId = (int)($params['id'] ?? 0);
        $vocabId = (int)($params['vocabId'] ?? 0);

        if ($userBookId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }
        if ($vocabId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'vocabId'], 'Invalid vocab id');
        }

        $repo = new VocabRepository();
        $ok = $repo->delete($userId, $userBookId, $vocabId);

        if (!$ok) {
            throw new HttpException(404, 'NOT_FOUND', [
                'userBookId' => $userBookId,
                'vocabId' => $vocabId,
            ], 'Vocab not found');
        }

        Response::ok(['deleted' => true]);
        // option: Response::noContent();
    }

    private function mapRow(array $r): array
    {
        return [
            'id' => (int)($r['id'] ?? 0),
            'word' => (string)($r['word'] ?? ''),
            'definition' => (string)($r['definition'] ?? ''),
            'createdAt' => $r['created_at'] ?? null,
        ];
    }
}
