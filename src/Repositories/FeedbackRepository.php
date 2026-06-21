<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;
use PDO;

final class FeedbackRepository
{
    public function findByUserId(int $userId): ?array
    {
        $pdo = DB::pdo();

        $stmt = $pdo->prepare("
            SELECT
                id,
                user_id,
                overall_experience,
                helpfulness,
                reading_motivation,
                favorite_features,
                improvement_priority,
                suggestion,
                created_at
            FROM feedback_submissions
            WHERE user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute(['user_id' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function create(int $userId, array $data): array
    {
        $pdo = DB::pdo();

        $stmt = $pdo->prepare("
            INSERT INTO feedback_submissions (
                user_id,
                overall_experience,
                helpfulness,
                reading_motivation,
                favorite_features,
                improvement_priority,
                suggestion
            ) VALUES (
                :user_id,
                :overall_experience,
                :helpfulness,
                :reading_motivation,
                :favorite_features,
                :improvement_priority,
                :suggestion
            )
        ");

        $stmt->execute([
            'user_id'              => $userId,
            'overall_experience'   => $data['overall_experience'],
            'helpfulness'          => $data['helpfulness'],
            'reading_motivation'   => $data['reading_motivation'],
            'favorite_features'    => json_encode(array_values($data['favorite_features'] ?? []), JSON_UNESCAPED_UNICODE),
            'improvement_priority' => $data['improvement_priority'],
            'suggestion'           => $data['suggestion'],
        ]);

        $id = (int)$pdo->lastInsertId();

        $created = $this->findById($id);

        if (!$created) {
            throw new \RuntimeException('Feedback created but could not be reloaded.');
        }

        return $created;
    }

    private function findById(int $id): ?array
    {
        $pdo = DB::pdo();

        $stmt = $pdo->prepare("
            SELECT
                id,
                user_id,
                overall_experience,
                helpfulness,
                reading_motivation,
                favorite_features,
                improvement_priority,
                suggestion,
                created_at
            FROM feedback_submissions
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
