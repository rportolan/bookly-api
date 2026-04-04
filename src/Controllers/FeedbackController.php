<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\FeedbackRepository;
use App\Repositories\ProgressRepository;
use App\Services\ProgressService;

final class FeedbackController
{
    public function me(): void
    {
        $userId = Auth::requireAuth();

        $repo = new FeedbackRepository();
        $row = $repo->findByUserId($userId);

        if (!$row) {
            Response::ok([
                'submitted' => false,
                'feedback' => null,
            ]);
            return;
        }

        Response::ok([
            'submitted' => true,
            'feedback' => $this->mapRow($row),
        ]);
    }

    public function submit(): void
    {
        $userId = Auth::requireAuth();
        $body = Request::json();

        $repo = new FeedbackRepository();
        $existing = $repo->findByUserId($userId);

        if ($existing) {
            throw new HttpException(
                409,
                'FEEDBACK_ALREADY_SUBMITTED',
                ['userId' => $userId],
                'Feedback already submitted'
            );
        }

        $overallExperience = trim((string)($body['overallExperience'] ?? ''));
        $helpfulness = trim((string)($body['helpfulness'] ?? ''));
        $readingMotivation = trim((string)($body['readingMotivation'] ?? ''));
        $favoriteFeatures = $body['favoriteFeatures'] ?? [];
        $painPoints = $body['painPoints'] ?? [];
        $improvementPriority = trim((string)($body['improvementPriority'] ?? ''));
        $desiredFeatures = $body['desiredFeatures'] ?? [];
        $readerProfile = trim((string)($body['readerProfile'] ?? ''));
        $improveOneThing = trim((string)($body['improveOneThing'] ?? ''));
        $suggestion = trim((string)($body['suggestion'] ?? ''));

        $this->assertRequiredChoice(
            $overallExperience,
            ['excellent', 'good', 'average', 'disappointing', 'bad'],
            'overallExperience'
        );

        $this->assertRequiredChoice(
            $helpfulness,
            ['a_lot', 'a_little', 'not_really', 'not_at_all'],
            'helpfulness'
        );

        $this->assertRequiredChoice(
            $readingMotivation,
            ['yes_a_lot', 'yes_a_little', 'not_really', 'no'],
            'readingMotivation'
        );

        $favoriteFeatures = $this->sanitizeArrayOfStrings(
            $favoriteFeatures,
            'favoriteFeatures',
            0,
            12,
            [
                'tracking',
                'daily_goals',
                'statistics',
                'quotes',
                'vocabulary',
                'summaries_analysis',
                'xp_levels',
                'cards_rewards',
                'design',
                'simplicity',
                'other',
            ],
            true
        );

        $painPoints = $this->sanitizeArrayOfStrings(
            $painPoints,
            'painPoints',
            0,
            12,
            [
                'design_lacks_polish',
                'lack_of_clarity',
                'features_not_useful',
                'missing_features',
                'gamification_not_interesting',
                'lack_of_fluidity',
                'dont_understand_what_to_do',
                'not_motivating_enough',
                'nothing_special',
                'other',
            ],
            true
        );

        $desiredFeatures = $this->sanitizeArrayOfStrings(
            $desiredFeatures,
            'desiredFeatures',
            0,
            12,
            [
                'more_gamification',
                'more_statistics',
                'better_retention',
                'more_personalization',
                'reading_recommendations',
                'more_simplicity',
                'more_goals_challenges',
                'more_educational_content',
                'other',
            ],
            true
        );

        $improvementPriority = $this->sanitizeChoiceWithOptionalOther(
            $improvementPriority,
            [
                'design_ui',
                'ux_fluidity',
                'reading_features',
                'statistics',
                'gamification',
                'quiz_content',
                'clarity',
                'other',
            ],
            'improvementPriority'
        );

        $this->assertRequiredChoice(
            $readerProfile,
            [
                'learn',
                'pleasure',
                'occasional',
                'habit',
                'retention',
                'mixed',
            ],
            'readerProfile'
        );

        if (mb_strlen($improveOneThing) > 1500) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'improveOneThing'],
                'improveOneThing is too long'
            );
        }

        if (mb_strlen($suggestion) > 1500) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'suggestion'],
                'suggestion is too long'
            );
        }

        $row = $repo->create($userId, [
            'overall_experience' => $overallExperience,
            'helpfulness' => $helpfulness,
            'reading_motivation' => $readingMotivation,
            'favorite_features' => $favoriteFeatures,
            'pain_points' => $painPoints,
            'improvement_priority' => $improvementPriority,
            'desired_features' => $desiredFeatures,
            'reader_profile' => $readerProfile,
            'improve_one_thing' => $improveOneThing !== '' ? $improveOneThing : null,
            'suggestion' => $suggestion !== '' ? $suggestion : null,
        ]);

        $progressService = new ProgressService();
        $progress = $progressService->snapshot($userId);

        try {
            $progressRepo = new ProgressRepository();
            $alreadyAwarded = $progressRepo->hasEventMeta(
                $userId,
                'FEEDBACK_SUBMITTED',
                'feedbackSubmissionId',
                (int)($row['id'] ?? 0)
            );

            if (!$alreadyAwarded) {
                $progress = $progressService->award($userId, 'FEEDBACK_SUBMITTED', 0, [
                    'feedbackSubmissionId' => (int)($row['id'] ?? 0),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[READOUT][XP] award FEEDBACK_SUBMITTED failed: ' . $e->getMessage());
            $progress = $progressService->snapshot($userId);
        }

        Response::created([
            'submitted' => true,
            'feedback' => $this->mapRow($row),
            'progress' => $this->onlyProgressSnapshot($progress),
            'levelUp' => $progress['levelUp'] ?? $this->emptyLevelUp(),
            'cardUnlock' => $progress['cardUnlock'] ?? $this->emptyCardUnlock(),
            'awardedXp' => (int)($progress['awardedXp'] ?? 0),
            'awardType' => (string)($progress['awardType'] ?? 'FEEDBACK_SUBMITTED'),
        ]);
    }

    private function assertRequiredChoice(string $value, array $allowed, string $field): void
    {
        if ($value === '' || !in_array($value, $allowed, true)) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => $field, 'allowed' => $allowed],
                'Invalid choice'
            );
        }
    }

    private function sanitizeArrayOfStrings(
        mixed $value,
        string $field,
        int $min = 0,
        int $max = 10,
        array $allowed = [],
        bool $allowOtherPrefix = false
    ): array {
        if (!is_array($value)) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => $field],
                'Expected array'
            );
        }

        $clean = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new HttpException(
                    422,
                    'VALIDATION_ERROR',
                    ['field' => $field],
                    'Array must contain only strings'
                );
            }

            $item = trim($item);
            if ($item === '') {
                continue;
            }

            if ($allowOtherPrefix && $this->isOtherPrefixedValue($item)) {
                $otherText = $this->extractOtherText($item);
                if ($otherText === '') {
                    throw new HttpException(
                        422,
                        'VALIDATION_ERROR',
                        ['field' => $field],
                        'Invalid other value'
                    );
                }
                $clean[] = 'other:' . $otherText;
                continue;
            }

            if (!empty($allowed) && !in_array($item, $allowed, true)) {
                throw new HttpException(
                    422,
                    'VALIDATION_ERROR',
                    ['field' => $field, 'allowed' => $allowed],
                    'Invalid array value'
                );
            }

            $clean[] = $item;
        }

        $clean = array_values(array_unique($clean));

        if (count($clean) < $min || count($clean) > $max) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => $field, 'min' => $min, 'max' => $max],
                'Invalid array size'
            );
        }

        return $clean;
    }

    private function sanitizeChoiceWithOptionalOther(
        string $value,
        array $allowed,
        string $field
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => $field, 'allowed' => $allowed],
                'Invalid choice'
            );
        }

        if ($this->isOtherPrefixedValue($value)) {
            $otherText = $this->extractOtherText($value);
            if ($otherText === '') {
                throw new HttpException(
                    422,
                    'VALIDATION_ERROR',
                    ['field' => $field],
                    'Invalid other value'
                );
            }
            return 'other:' . $otherText;
        }

        if (!in_array($value, $allowed, true)) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => $field, 'allowed' => $allowed],
                'Invalid choice'
            );
        }

        return $value;
    }

    private function isOtherPrefixedValue(string $value): bool
    {
        return str_starts_with($value, 'other:');
    }

    private function extractOtherText(string $value): string
    {
        $text = trim(substr($value, 6));

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) > 255) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'other'],
                'Other value is too long'
            );
        }

        return $text;
    }

    private function mapRow(array $r): array
    {
        return [
            'id' => (int)($r['id'] ?? 0),
            'userId' => (int)($r['user_id'] ?? 0),
            'overallExperience' => (string)($r['overall_experience'] ?? ''),
            'helpfulness' => (string)($r['helpfulness'] ?? ''),
            'readingMotivation' => (string)($r['reading_motivation'] ?? ''),
            'favoriteFeatures' => $this->decodeJsonArray($r['favorite_features'] ?? null),
            'painPoints' => $this->decodeJsonArray($r['pain_points'] ?? null),
            'improvementPriority' => (string)($r['improvement_priority'] ?? ''),
            'desiredFeatures' => $this->decodeJsonArray($r['desired_features'] ?? null),
            'readerProfile' => (string)($r['reader_profile'] ?? ''),
            'improveOneThing' => $r['improve_one_thing'] ?? null,
            'suggestion' => $r['suggestion'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
        ];
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? array_values($decoded) : [];
        } catch (\Throwable) {
            return [];
        }
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

    private function emptyLevelUp(): array
    {
        return [
            'happened' => false,
            'previousLevel' => 1,
            'newLevel' => 1,
            'previousTitle' => 'Lecteur novice',
            'newTitle' => 'Lecteur novice',
        ];
    }

    private function emptyCardUnlock(): array
    {
        return [
            'happened' => false,
            'cards' => [],
        ];
    }
}