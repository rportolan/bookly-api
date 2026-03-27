<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\BookRepository;
use App\Repositories\OpenLibraryCacheRepository;
use App\Repositories\ProgressRepository;
use App\Services\OpenLibraryClient;
use App\Services\ProgressService;

final class OpenLibraryController
{
    public function search(): void
    {
        Auth::requireAuth();

        $q = trim((string) Request::query('q', ''));
        if ($q === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'q'], 'Missing q');
        }

        $limit = (int) Request::query('limit', (string) Env::get('OPEN_LIBRARY_MAX_RESULTS', '10'));
        $limit = max(1, min(20, $limit));

        $cacheTtl = (int) (Env::get('OPEN_LIBRARY_CACHE_TTL_SECONDS', '604800'));
        $cacheKey = $this->makeCacheKey($q, $limit);

        $cacheRepo = new OpenLibraryCacheRepository();
        $cached = $cacheRepo->getValid($cacheKey);

        if ($cached) {
            $payload = json_decode($cached['response_json'] ?? '[]', true) ?: [];
            Response::ok($this->normalizeSearchResponse($payload, true));
            return;
        }

        $client = new OpenLibraryClient();
        $raw = $client->searchBooks($q, $limit);

        $cacheRepo->upsert(
            $cacheKey,
            $q,
            json_encode($raw, JSON_UNESCAPED_UNICODE),
            $cacheTtl
        );

        Response::ok($this->normalizeSearchResponse($raw, false));
    }

    public function import(): void
    {
        $userId = Auth::requireAuth();
        $body = Request::json();

        $editionId = trim((string) ($body['openLibraryEditionId'] ?? ''));
        if ($editionId === '') {
            throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'openLibraryEditionId'],
                'Missing openLibraryEditionId'
            );
        }

        $client = new OpenLibraryClient();

        $edition = $client->getEdition($editionId);
        $payload = $this->mapEditionToBookPayload($client, $edition);

        $payload['status'] = $body['status'] ?? 'À lire';
        $payload['progressPages'] = isset($body['progressPages']) ? (int) $body['progressPages'] : 0;

        $repo = new BookRepository();

        $catalogBookId = $repo->upsertCatalogBookFromOpenLibrary($payload);
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
                error_log('[BOOKLY][XP] award BOOK_CREATED (open library import) failed: ' . $e->getMessage());
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

    private function makeCacheKey(string $q, int $limit): string
    {
        $norm = $this->normalizeQuery($q);

        return hash('sha256', json_encode([
            'q' => $norm,
            'limit' => $limit,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function normalizeQuery(string $q): string
    {
        $q = trim(mb_strtolower($q));
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;
        return $q;
    }

    private function normalizeSearchResponse(array $raw, bool $cacheHit): array
    {
        $items = [];
        foreach (($raw['docs'] ?? []) as $doc) {
            $normalized = $this->normalizeDocItem($doc);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return [
            'meta' => [
                'cacheHit' => $cacheHit,
                'totalItems' => (int) ($raw['numFound'] ?? count($items)),
            ],
            'items' => $items,
        ];
    }

    private function normalizeDocItem(array $doc): ?array
    {
        $editionIds = $doc['edition_key'] ?? [];
        if (!is_array($editionIds) || count($editionIds) === 0) {
            return null;
        }

        $editionId = trim((string) $editionIds[0]);
        if ($editionId === '') {
            return null;
        }

        $authorNames = $doc['author_name'] ?? [];
        if (!is_array($authorNames)) {
            $authorNames = [];
        }

        $publishers = $doc['publisher'] ?? [];
        if (!is_array($publishers)) {
            $publishers = [];
        }

        $isbns = $doc['isbn'] ?? [];
        if (!is_array($isbns)) {
            $isbns = [];
        }

        [$isbn10, $isbn13] = $this->extractIsbns($isbns);

        $coverUrl = null;
        if (isset($doc['cover_i']) && is_numeric($doc['cover_i'])) {
            $coverUrl = (new OpenLibraryClient())->coverUrlFromCoverId((int) $doc['cover_i'], 'L');
        } else {
            $coverUrl = (new OpenLibraryClient())->coverUrlFromEdition($editionId, 'L');
        }

        $workId = $this->extractOlid($doc['key'] ?? null, '/works/');

        return [
            'openLibraryEditionId' => $editionId,
            'openLibraryWorkId' => $workId,
            'title' => (string) ($doc['title'] ?? ''),
            'author' => count($authorNames) ? (string) $authorNames[0] : '',
            'authors' => array_values(array_map('strval', $authorNames)),
            'publisher' => count($publishers) ? (string) $publishers[0] : null,
            'pages' => isset($doc['number_of_pages_median']) ? (int) $doc['number_of_pages_median'] : 0,
            'coverUrl' => $coverUrl,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'publishedDate' => $doc['first_publish_year'] ?? null,
            'description' => null,
            'language' => null,
            'genre' => $this->simplifyGenre($doc['subject'] ?? null),
        ];
    }

    private function extractIsbns(array $isbns): array
    {
        $isbn10 = null;
        $isbn13 = null;

        foreach ($isbns as $isbn) {
            $isbn = preg_replace('/[^0-9Xx]/', '', (string) $isbn) ?? '';
            $len = strlen($isbn);

            if ($len === 10 && $isbn10 === null) {
                $isbn10 = $isbn;
            }

            if ($len === 13 && $isbn13 === null) {
                $isbn13 = $isbn;
            }

            if ($isbn10 !== null && $isbn13 !== null) {
                break;
            }
        }

        return [$isbn10, $isbn13];
    }

    private function mapEditionToBookPayload(OpenLibraryClient $client, array $edition): array
    {
        $editionId = $this->extractOlid($edition['key'] ?? null, '/books/');
        if ($editionId === null || $editionId === '') {
            throw new HttpException(
                502,
                'OPEN_LIBRARY_BAD_RESPONSE',
                ['reason' => 'missing edition key'],
                'Open Library bad response'
            );
        }

        $workId = null;
        $work = null;
        if (isset($edition['works']) && is_array($edition['works']) && count($edition['works']) > 0) {
            $workKey = $edition['works'][0]['key'] ?? null;
            $workId = $this->extractOlid($workKey, '/works/');

            if ($workId) {
                try {
                    $work = $client->getWork($workId);
                } catch (\Throwable $e) {
                    error_log('[BOOKLY][OpenLibrary] getWork failed: ' . $e->getMessage());
                }
            }
        }

        $authors = $this->resolveEditionAuthors($client, $edition);
        $publisher = null;
        if (isset($edition['publishers']) && is_array($edition['publishers']) && count($edition['publishers']) > 0) {
            $publisher = (string) $edition['publishers'][0];
        }

        $isbn10 = null;
        if (isset($edition['isbn_10']) && is_array($edition['isbn_10']) && count($edition['isbn_10']) > 0) {
            $isbn10 = (string) $edition['isbn_10'][0];
        }

        $isbn13 = null;
        if (isset($edition['isbn_13']) && is_array($edition['isbn_13']) && count($edition['isbn_13']) > 0) {
            $isbn13 = (string) $edition['isbn_13'][0];
        }

        $genre = null;
        if (is_array($work)) {
            $genre = $this->simplifyGenre($work['subjects'] ?? null);
        }

        $summary = $this->extractDescription($edition);
        if ($summary === null && is_array($work)) {
            $summary = $this->extractDescription($work);
        }

        $pages = 0;
        if (isset($edition['number_of_pages']) && is_numeric($edition['number_of_pages'])) {
            $pages = (int) $edition['number_of_pages'];
        }

        $coverUrl = null;
        if (isset($edition['covers']) && is_array($edition['covers']) && count($edition['covers']) > 0 && is_numeric($edition['covers'][0])) {
            $coverUrl = $client->coverUrlFromCoverId((int) $edition['covers'][0], 'L');
        } else {
            $coverUrl = $client->coverUrlFromEdition($editionId, 'L');
        }

        return [
            'openLibraryEditionId' => $editionId,
            'openLibraryWorkId' => $workId,
            'title' => (string) ($edition['title'] ?? ''),
            'author' => count($authors) ? (string) $authors[0] : '',
            'genre' => $genre,
            'pages' => $pages,
            'publisher' => $publisher,
            'coverUrl' => $coverUrl,
            'summary' => $summary,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
        ];
    }

    private function resolveEditionAuthors(OpenLibraryClient $client, array $edition): array
    {
        $names = [];

        $authors = $edition['authors'] ?? [];
        if (!is_array($authors)) {
            return $names;
        }

        foreach ($authors as $authorRef) {
            if (!is_array($authorRef)) {
                continue;
            }

            $key = $authorRef['key'] ?? ($authorRef['author']['key'] ?? null);
            $authorId = $this->extractOlid($key, '/authors/');
            if (!$authorId) {
                continue;
            }

            try {
                $author = $client->getAuthor($authorId);
                $name = trim((string) ($author['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][OpenLibrary] getAuthor failed: ' . $e->getMessage());
            }
        }

        return array_values(array_unique($names));
    }

    private function extractDescription(array $data): ?string
    {
        $desc = $data['description'] ?? null;

        if (is_string($desc)) {
            $desc = trim($desc);
            return $desc !== '' ? $desc : null;
        }

        if (is_array($desc)) {
            $value = trim((string) ($desc['value'] ?? ''));
            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function extractOlid(mixed $value, string $prefix): ?string
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        if (str_starts_with($s, $prefix)) {
            $s = substr($s, strlen($prefix));
        }

        $s = trim($s, '/');
        return $s !== '' ? $s : null;
    }

    private function simplifyGenre(mixed $subjects): ?string
    {
        if (!is_array($subjects) || count($subjects) === 0) {
            return null;
        }

        foreach ($subjects as $subject) {
            $s = trim((string) $subject);
            if ($s === '') {
                continue;
            }

            if (mb_strlen($s) > 60) {
                $s = mb_substr($s, 0, 60);
            }

            return $s;
        }

        return null;
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
            'id' => (int) $r['id'],
            'bookId' => (int) $r['bookId'],
            'title' => (string) ($r['title'] ?? ''),
            'author' => (string) ($r['author'] ?? ''),
            'genre' => $r['genre'] ?? null,
            'pages' => (int) ($r['pages'] ?? 0),
            'progressPages' => (int) ($r['progress_pages'] ?? 0),
            'status' => (string) ($r['status'] ?? 'À lire'),
            'startedAt' => $r['started_at'] ?? null,
            'finishedAt' => $r['finished_at'] ?? null,
            'publisher' => $r['publisher'] ?? null,
            'rating' => isset($r['rating']) && $r['rating'] !== null ? (float) $r['rating'] : null,
            'summary' => $r['summary'] ?? null,
            'analysisWork' => $r['analysis_work'] ?? null,
            'coverUrl' => $r['cover_url'] ?? null,
            'createdAt' => $r['created_at'] ?? null,
            'updatedAt' => $r['updated_at'] ?? null,
        ];
    }
}