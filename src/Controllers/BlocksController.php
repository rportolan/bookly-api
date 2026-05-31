<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\BlockRepository;

final class BlocksController
{
    private const VALID_TYPES = ['analysis', 'quotes', 'vocab', 'chapters', 'characters'];

    public function index(array $params): void
    {
        $userId     = Auth::requireAuth();
        $userBookId = $this->bookId($params);

        $repo = new BlockRepository();
        $rows = $repo->listForBook($userId, $userBookId);

        Response::ok(array_map([$this, 'mapRow'], $rows));
    }

    public function store(array $params): void
    {
        $userId     = Auth::requireAuth();
        $userBookId = $this->bookId($params);

        $body = Request::json();
        $type = trim((string)($body['type'] ?? ''));

        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'type', 'allowed' => self::VALID_TYPES], 'Invalid block type');
        }

        $repo = new BlockRepository();

        try {
            $row = $repo->add($userId, $userBookId, $type);
        } catch (\RuntimeException) {
            throw new HttpException(404, 'NOT_FOUND', ['userBookId' => $userBookId], 'Book not found');
        }

        Response::created($this->mapRow($row));
    }

    public function destroy(array $params): void
    {
        $userId     = Auth::requireAuth();
        $userBookId = $this->bookId($params);

        $type = trim((string)($params['type'] ?? ''));
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'type'], 'Invalid block type');
        }

        $repo = new BlockRepository();
        $ok   = $repo->remove($userId, $userBookId, $type);

        if (!$ok) {
            throw new HttpException(404, 'NOT_FOUND', ['userBookId' => $userBookId, 'type' => $type], 'Block not found');
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
            'type'      => (string)$r['type'],
            'sortOrder' => (int)$r['sort_order'],
        ];
    }
}
