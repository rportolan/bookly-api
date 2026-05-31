<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\CharacterRepository;

final class CharactersController
{
    public function index(array $params): void
    {
        $userId     = Auth::requireAuth();
        $userBookId = $this->bookId($params);

        $repo = new CharacterRepository();
        $rows = $repo->listForBook($userId, $userBookId);

        Response::ok(array_map([$this, 'mapRow'], $rows));
    }

    public function store(array $params): void
    {
        $userId     = Auth::requireAuth();
        $userBookId = $this->bookId($params);

        $body        = Request::json();
        $name        = trim((string)($body['name'] ?? ''));
        $role        = isset($body['role']) ? trim((string)$body['role']) : null;
        $description = isset($body['description']) ? trim((string)$body['description']) : null;

        if ($role === '') $role = null;
        if ($description === '') $description = null;

        if ($name === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'name'], 'name is required');
        }
        if (mb_strlen($name) > 255) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'name'], 'name is too long');
        }
        if ($role !== null && mb_strlen($role) > 255) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'role'], 'role is too long');
        }

        $repo = new CharacterRepository();

        try {
            $row = $repo->create($userId, $userBookId, $name, $role, $description);
        } catch (\RuntimeException) {
            throw new HttpException(404, 'NOT_FOUND', ['userBookId' => $userBookId], 'Book not found');
        }

        Response::created($this->mapRow($row));
    }

    public function update(array $params): void
    {
        $userId      = Auth::requireAuth();
        $userBookId  = $this->bookId($params);
        $characterId = (int)($params['charId'] ?? 0);

        if ($characterId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'charId'], 'Invalid character id');
        }

        $body        = Request::json();
        $name        = trim((string)($body['name'] ?? ''));
        $role        = isset($body['role']) ? trim((string)$body['role']) : null;
        $description = isset($body['description']) ? trim((string)$body['description']) : null;

        if ($role === '') $role = null;
        if ($description === '') $description = null;

        if ($name === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'name'], 'name is required');
        }

        $repo = new CharacterRepository();
        $row  = $repo->update($userId, $userBookId, $characterId, $name, $role, $description);

        if (!$row) {
            throw new HttpException(404, 'NOT_FOUND', ['characterId' => $characterId], 'Character not found');
        }

        Response::ok($this->mapRow($row));
    }

    public function destroy(array $params): void
    {
        $userId      = Auth::requireAuth();
        $userBookId  = $this->bookId($params);
        $characterId = (int)($params['charId'] ?? 0);

        if ($characterId <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'charId'], 'Invalid character id');
        }

        $repo = new CharacterRepository();
        $ok   = $repo->delete($userId, $userBookId, $characterId);

        if (!$ok) {
            throw new HttpException(404, 'NOT_FOUND', ['characterId' => $characterId], 'Character not found');
        }

        Response::ok(['deleted' => true]);
    }

    private function bookId(array $params): int
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }
        return $id;
    }

    private function mapRow(array $r): array
    {
        return [
            'id'          => (int)$r['id'],
            'name'        => (string)$r['name'],
            'role'        => isset($r['role']) && $r['role'] !== '' ? (string)$r['role'] : null,
            'description' => isset($r['description']) && $r['description'] !== '' ? (string)$r['description'] : null,
            'createdAt'   => $r['created_at'] ?? null,
        ];
    }
}
