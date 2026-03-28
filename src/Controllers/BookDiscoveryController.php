<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\BookRepository;
use App\Repositories\ProgressRepository;
use App\Services\BookDiscoveryService;
use App\Services\ProgressService;

final class BookDiscoveryController
{
    public function search(): void
    {
        Auth::requireAuth();

        $q = trim((string) Request::query('q', ''));
        if ($q === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'q'], 'Missing q');
        }

        $limit = (int) Request::query('limit', '10');
        $limit = max(1, min(20, $limit));

        $service = new BookDiscoveryService();
        $payload = $service->search($q, $limit);

        Response::ok($payload);
    }

    public function import(): void
    {
        $userId = Auth::requireAuth();
        $body = Request::json();

        $provider = trim((string) ($body['provider'] ?? ''));
        $providerBookId = trim((string) ($body['providerBookId'] ?? ''));

        if ($provider === '' || $providerBookId === '') {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['required' => ['provider', 'providerBookId']],
                'Missing required fields'
            );
        }

        $allowedProviders = ['isbndb', 'google_books', 'open_library'];
        if (!in_array($provider, $allowedProviders, true)) {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'provider', 'allowed' => $allowedProviders],
                'Invalid provider'
            );
        }

        $service = new BookDiscoveryService();
        $payload = $service->fetchImportPayload($provider, $providerBookId);

        $payload['status'] = $body['status'] ?? 'À lire';
        $payload['progressPages'] = isset($body['progressPages']) ? max(0, (int) $body['progressPages']) : 0;
        $payload['summary'] = $body['summary'] ?? ($payload['summary'] ?? ($payload['description'] ?? null));
        $payload['analysisWork'] = $body['analysisWork'] ?? null;
        $payload['rating'] = $body['rating'] ?? null;
        $payload['startedAt'] = $body['startedAt'] ?? null;
        $payload['finishedAt'] = $body['finishedAt'] ?? null;

        $repo = new BookRepository();

        $catalogBookId = match ($provider) {
            'isbndb' => $repo->upsertCatalogBookFromIsbnDb($payload),
            'google_books' => $repo->upsertCatalogBookFromGoogle($payload),
            'open_library' => $repo->upsertCatalogBookFromOpenLibrary($payload),
        };

        $isNewUserBook = !$repo->userBookExists($userId, $catalogBookId);
        $row = $repo->createUserBookIfNotExists($userId, $catalogBookId, $payload);

        $progressService = new ProgressService();
        $progress = $progressService->snapshot($userId);

        if ($isNewUserBook) {
            try {
                $userBookId = (int) ($row['id'] ?? 0);

                $pr = new ProgressRepository();
                $already = $pr->hasEventMeta($userId, 'BOOK_CREATED', 'userBookId', $userBookId);

                if (!$already) {
                    $progress = $progressService->award($userId, 'BOOK_CREATED', 0, [
                        'userBookId' => $userBookId,
                        'bookId' => $catalogBookId,
                        'title' => (string) ($row['title'] ?? ''),
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][XP] award BOOK_CREATED (book discovery import) failed: ' . $e->getMessage());
                $progress = $progressService->snapshot($userId);
            }
        }

        Response::created([
            ...$this->mapUserBookRow($row),
            'progress' => $this->onlyProgressSnapshot($progress),
            'levelUp' => $progress['levelUp'] ?? $this->emptyLevelUp(),
            'cardUnlock' => $progress['cardUnlock'] ?? $this->emptyCardUnlock(),
            'awardedXp' => (int) ($progress['awardedXp'] ?? 0),
            'awardType' => (string) ($progress['awardType'] ?? 'BOOK_CREATED'),
        ]);
    }

    private function onlyProgressSnapshot(array $progress): array
    {
        return [
            'xp' => (int) ($progress['xp'] ?? 0),
            'level' => (int) ($progress['level'] ?? 1),
            'title' => (string) ($progress['title'] ?? 'Lecteur novice'),
            'progressPct' => (int) ($progress['progressPct'] ?? 0),
            'xpToNext' => (int) ($progress['xpToNext'] ?? 0),
            'levelXp' => (int) ($progress['levelXp'] ?? 0),
            'levelXpSpan' => (int) ($progress['levelXpSpan'] ?? 1),
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

    private function mapUserBookRow(array $r): array
    {
        return [
            'id' => (int) ($r['id'] ?? 0),
            'bookId' => (int) ($r['bookId'] ?? 0),
            'title' => (string) ($r['title'] ?? ''),
            'author' => (string) ($r['author'] ?? ''),
            'genre' => $r['genre'] ?? null,
            'pages' => (int) ($r['pages'] ?? 0),
            'progressPages' => (int) ($r['progress_pages'] ?? 0),
            'status' => (string) ($r['status'] ?? 'À lire'),
            'startedAt' => $r['started_at'] ?? null,
            'finishedAt' => $r['finished_at'] ?? null,
            'publisher' => $r['publisher'] ?? null,
            'isbn10' => $r['isbn10'] ?? null,
            'isbn13' => $r['isbn13'] ?? null,
            'publishedDate' => $r['published_date'] ?? null,
            'language' => $r['language'] ?? null,
            'metadataSource' => $r['metadata_source'] ?? null,
            'summary' => $r['summary'] ?? null,
            'analysisWork' => $r['analysis_work'] ?? null,
            'coverUrl' => $r['cover_url'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }
}