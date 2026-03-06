<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class QuizCategoryRepository
{
    public function list(): array
    {
        $stmt = Db::pdo()->query("
            SELECT id, name, slug, icon, sort_order
            FROM quiz_categories
            ORDER BY sort_order ASC, id ASC
        ");
        return $stmt->fetchAll() ?: [];
    }
}