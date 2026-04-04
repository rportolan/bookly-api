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
                pain_points,
                improvement_priority,
                desired_features,
                reader_profile,
                improve_one_thing,
                suggestion,
                created_at
            FROM feedback_submissions
            WHERE user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            'user_id' => $userId,
        ]);

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
                pain_points,
                improvement_priority,
                desired_features,
                reader_profile,
                improve_one_thing,
                suggestion
            ) VALUES (
                :user_id,
                :overall_experience,
                :helpfulness,
                :reading_motivation,
                :favorite_features,
                :pain_points,
                :improvement_priority,
                :desired_features,
                :reader_profile,
                :improve_one_thing,
                :suggestion
            )
        ");

        $stmt->execute([
            'user_id' => $userId,
            'overall_experience' => $data['overall_experience'],
            'helpfulness' => $data['helpfulness'],
            'reading_motivation' => $data['reading_motivation'],
            'favorite_features' => json_encode(array_values($data['favorite_features'] ?? []), JSON_UNESCAPED_UNICODE),
            'pain_points' => json_encode(array_values($data['pain_points'] ?? []), JSON_UNESCAPED_UNICODE),
            'improvement_priority' => $data['improvement_priority'],
            'desired_features' => json_encode(array_values($data['desired_features'] ?? []), JSON_UNESCAPED_UNICODE),
            'reader_profile' => $data['reader_profile'],
            'improve_one_thing' => $data['improve_one_thing'],
            'suggestion' => $data['suggestion'],
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
                pain_points,
                improvement_priority,
                desired_features,
                reader_profile,
                improve_one_thing,
                suggestion,
                created_at
            FROM feedback_submissions
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => $id,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}