<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\AnalysisRepository;

final class AnalysisController
{
    public function show(array $params): void
    {
        $userId     = Auth::requireAuth();
        $userBookId = $this->bookId($params);

        $repo = new AnalysisRepository();
        $row  = $repo->get($userId, $userBookId);

        Response::ok(['content' => $row ? (string)$row['content'] : '', 'updatedAt' => $row['updated_at'] ?? null]);
    }

    public function upsert(array $params): void
    {
        $userId     = Auth::requireAuth();
        $userBookId = $this->bookId($params);

        $body    = Request::json();
        $content = (string)($body['content'] ?? '');

        $repo = new AnalysisRepository();

        try {
            $row = $repo->upsert($userId, $userBookId, $content);
        } catch (\RuntimeException) {
            throw new HttpException(404, 'NOT_FOUND', ['userBookId' => $userBookId], 'Book not found');
        }

        Response::ok(['content' => (string)$row['content'], 'updatedAt' => $row['updated_at'] ?? null]);
    }

    private function bookId(array $params): int
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'id'], 'Invalid book id');
        }
        return $id;
    }
}
