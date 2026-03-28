<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\HttpException;
use App\Repositories\GoogleBooksCacheRepository;
use App\Repositories\IsbnDbCacheRepository;
use App\Repositories\OpenLibraryCacheRepository;

final class BookDiscoveryService
{
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'q'], 'Missing q');
        }

        $limit = max(1, min(20, $limit));

        $isbnDb = $this->searchIsbnDb($query, min(20, max($limit, 10)));
        $isbnDbItems = $this->scoreAndSortItems($isbnDb['items'] ?? [], $query);

        $shouldUseGoogleFallback = $this->shouldUseGoogleFallback($isbnDbItems, $limit, $query);
        $shouldUseOpenLibraryFallback = $this->shouldUseOpenLibraryFallback($isbnDbItems, $limit, $query);

        $googleItems = [];
        $openItems = [];

        if ($shouldUseGoogleFallback) {
            $google = $this->searchGoogle($query, min(20, max($limit, 10)));
            $googleItems = $this->scoreAndSortItems($google['items'] ?? [], $query);
        }

        if ($shouldUseOpenLibraryFallback) {
            $open = $this->searchOpenLibrary($query, min(20, max($limit, 10)));
            $openItems = $this->scoreAndSortItems($open['items'] ?? [], $query);
        }

        $merged = $this->mergeResults($isbnDbItems, $googleItems, $openItems, $query);
        $responseItems = array_slice($merged, 0, $limit);

        return [
            'meta' => [
                'primarySource' => 'isbndb',
                'totalItems' => count($responseItems),
                'hasIsbnDbResults' => count($isbnDbItems) > 0,
                'googleFallbackUsed' => $shouldUseGoogleFallback && $this->containsPrimarySource($responseItems, 'google_books'),
                'openLibraryFallbackUsed' => $shouldUseOpenLibraryFallback && $this->containsPrimarySource($responseItems, 'open_library'),
                'googleEnrichmentUsed' => $this->containsAlternateSource($responseItems, 'google_books'),
                'openLibraryEnrichmentUsed' => $this->containsAlternateSource($responseItems, 'open_library'),
            ],
            'items' => $responseItems,
        ];
    }

    public function fetchImportPayload(string $provider, string $providerBookId): array
    {
        $provider = trim($provider);
        $providerBookId = trim($providerBookId);

        if ($provider === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'provider'], 'Missing provider');
        }

        if ($providerBookId === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'providerBookId'], 'Missing providerBookId');
        }

        return match ($provider) {
            'isbndb' => $this->fetchIsbnDbImportPayload($providerBookId),
            'google_books' => $this->fetchGoogleImportPayload($providerBookId),
            'open_library' => $this->fetchOpenLibraryImportPayload($providerBookId),
            default => throw new HttpException(
                422,
                'VALIDATION_ERROR',
                ['field' => 'provider', 'allowed' => ['isbndb', 'google_books', 'open_library']],
                'Invalid provider'
            ),
        };
    }

    private function fetchIsbnDbImportPayload(string $providerBookId): array
    {
        $raw = (new IsbnDbClient())->getBook($providerBookId);
        $book = is_array($raw['book'] ?? null) ? $raw['book'] : $raw;

        $item = $this->normalizeIsbnDbBook($book);

        return [
            ...$item,
            'summary' => $item['description'],
        ];
    }

    private function fetchGoogleImportPayload(string $providerBookId): array
    {
        $volume = (new GoogleBooksClient())->getVolume($providerBookId);
        $item = $this->normalizeGoogleVolumeItem($volume);

        return [
            ...$item,
            'summary' => $item['description'],
        ];
    }

    private function fetchOpenLibraryImportPayload(string $providerBookId): array
    {
        $client = new OpenLibraryClient();
        $edition = $client->getEdition($providerBookId);

        return $this->normalizeOpenLibraryEditionForImport($client, $edition);
    }

    private function searchIsbnDb(string $query, int $limit): array
    {
        $cacheTtl = (int) Env::get('ISBNDB_CACHE_TTL_SECONDS', '604800');
        $cacheKey = hash('sha256', json_encode([
            'provider' => 'isbndb',
            'q' => $this->normalizeQuery($query),
            'limit' => $limit,
            'v' => 'search_v2',
        ], JSON_UNESCAPED_UNICODE));

        $cacheRepo = new IsbnDbCacheRepository();
        $cached = $cacheRepo->getValid($cacheKey);

        if ($cached) {
            $payload = json_decode($cached['response_json'] ?? '[]', true) ?: [];
            return is_array($payload) ? $payload : ['items' => []];
        }

        $items = [];

        try {
            $raw = (new IsbnDbClient())->searchBooks($query, $limit, 1, $this->guessIsbnDbColumn($query));
            $books = $raw['books'] ?? [];

            if (is_array($books)) {
                foreach ($books as $book) {
                    if (!is_array($book)) {
                        continue;
                    }

                    $normalized = $this->normalizeIsbnDbBook($book);
                    if ($normalized !== null) {
                        $items[] = $normalized;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[BOOKLY][ISBNDB search] ' . $e->getMessage());
        }

        $response = [
            'meta' => [
                'cacheHit' => false,
                'provider' => 'isbndb',
                'totalItems' => count($items),
            ],
            'items' => $items,
        ];

        $cacheRepo->upsert(
            $cacheKey,
            $query,
            json_encode($response, JSON_UNESCAPED_UNICODE),
            $cacheTtl
        );

        return $response;
    }

    private function searchGoogle(string $query, int $limit): array
    {
        $cacheTtl = (int) Env::get('GOOGLE_BOOKS_CACHE_TTL_SECONDS', '604800');
        $lang = (string) (Env::get('GOOGLE_BOOKS_LANG', 'fr') ?? 'fr');
        $country = (string) (Env::get('GOOGLE_BOOKS_COUNTRY', 'FR') ?? 'FR');

        $cacheKey = hash('sha256', json_encode([
            'provider' => 'google_books_fallback',
            'q' => $this->normalizeQuery($query),
            'limit' => $limit,
            'lang' => strtolower($lang),
            'country' => strtoupper($country),
            'v' => 'search_v2',
        ], JSON_UNESCAPED_UNICODE));

        $cacheRepo = new GoogleBooksCacheRepository();
        $cached = $cacheRepo->getValid($cacheKey);

        if ($cached) {
            $payload = json_decode($cached['response_json'] ?? '[]', true) ?: [];
            return is_array($payload) ? $payload : ['items' => []];
        }

        $items = [];

        try {
            $client = new GoogleBooksClient();
            $raw = $this->looksLikeIsbn($query)
                ? $client->searchByIsbn($this->normalizeIsbn($query), $limit, $lang, $country)
                : $client->searchVolumes($query, $limit, $lang, $country);

            foreach (($raw['items'] ?? []) as $it) {
                if (!is_array($it)) {
                    continue;
                }

                $normalized = $this->normalizeGoogleVolumeItem($it);
                if ($normalized !== null) {
                    $items[] = $normalized;
                }
            }
        } catch (\Throwable $e) {
            error_log('[BOOKLY][Google fallback search] ' . $e->getMessage());
        }

        $response = [
            'meta' => [
                'cacheHit' => false,
                'provider' => 'google_books',
                'totalItems' => count($items),
            ],
            'items' => $items,
        ];

        $cacheRepo->upsert(
            $cacheKey,
            $query,
            json_encode($response, JSON_UNESCAPED_UNICODE),
            $cacheTtl
        );

        return $response;
    }

    private function searchOpenLibrary(string $query, int $limit): array
    {
        $cacheTtl = (int) Env::get('OPEN_LIBRARY_CACHE_TTL_SECONDS', '604800');
        $cacheKey = hash('sha256', json_encode([
            'provider' => 'open_library_fallback',
            'q' => $this->normalizeQuery($query),
            'limit' => $limit,
            'v' => 'search_v2',
        ], JSON_UNESCAPED_UNICODE));

        $cacheRepo = new OpenLibraryCacheRepository();
        $cached = $cacheRepo->getValid($cacheKey);

        if ($cached) {
            $payload = json_decode($cached['response_json'] ?? '[]', true) ?: [];
            return is_array($payload) ? $payload : ['items' => []];
        }

        $items = [];

        try {
            $client = new OpenLibraryClient();
            $raw = $this->looksLikeIsbn($query)
                ? $client->searchByIsbn($this->normalizeIsbn($query))
                : $client->searchBooks($query, $limit);

            foreach (($raw['docs'] ?? []) as $doc) {
                if (!is_array($doc)) {
                    continue;
                }

                $normalized = $this->normalizeOpenLibraryDoc($doc);
                if ($normalized !== null) {
                    $items[] = $normalized;
                }
            }
        } catch (\Throwable $e) {
            error_log('[BOOKLY][Open Library fallback search] ' . $e->getMessage());
        }

        $response = [
            'meta' => [
                'cacheHit' => false,
                'provider' => 'open_library',
                'totalItems' => count($items),
            ],
            'items' => $items,
        ];

        $cacheRepo->upsert(
            $cacheKey,
            $query,
            json_encode($response, JSON_UNESCAPED_UNICODE),
            $cacheTtl
        );

        return $response;
    }

    private function shouldUseGoogleFallback(array $isbnDbItems, int $limit, string $query): bool
    {
        if (count($isbnDbItems) === 0) {
            return true;
        }

        $strongCount = 0;
        foreach (array_slice($isbnDbItems, 0, $limit) as $item) {
            if (($item['qualityScore'] ?? 0) >= 60) {
                $strongCount++;
            }
        }

        if ($strongCount >= min(5, $limit)) {
            return false;
        }

        if ($this->queryLooksFrench($query) && !$this->hasStrongFrenchCoverage($isbnDbItems, $limit)) {
            return true;
        }

        return count($isbnDbItems) < $limit;
    }

    private function shouldUseOpenLibraryFallback(array $isbnDbItems, int $limit, string $query): bool
    {
        if (count($isbnDbItems) === 0) {
            return true;
        }

        if ($this->queryLooksFrench($query) && !$this->hasStrongFrenchCoverage($isbnDbItems, $limit)) {
            return true;
        }

        return count($isbnDbItems) < max(3, (int) ceil($limit / 2));
    }

    private function hasStrongFrenchCoverage(array $items, int $limit): bool
    {
        $count = 0;

        foreach (array_slice($items, 0, $limit) as $item) {
            $lang = mb_strtolower(trim((string) ($item['language'] ?? '')));
            if ($lang === 'fr' || $lang === 'fre' || $lang === 'french' || $lang === 'français') {
                $count++;
            }
        }

        return $count >= 1;
    }

    private function mergeResults(array $isbndb, array $google, array $open, string $query): array
    {
        $bucket = [];

        foreach ([$isbndb, $google, $open] as $group) {
            foreach ($group as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $key = $this->resultKey($item);

                if (!isset($bucket[$key])) {
                    $bucket[$key] = $item;
                    continue;
                }

                $bucket[$key] = $this->mergeTwo($bucket[$key], $item);
            }
        }

        $items = array_values($bucket);

        foreach ($items as &$item) {
            $item['qualityScore'] = $this->qualityScore($item, $query);
        }
        unset($item);

        usort($items, function (array $a, array $b): int {
            return ($b['qualityScore'] ?? 0) <=> ($a['qualityScore'] ?? 0);
        });

        return array_values(array_filter($items, fn(array $item): bool => ($item['qualityScore'] ?? 0) > 0));
    }

    private function mergeTwo(array $left, array $right): array
    {
        $priority = [
            'isbndb' => 3,
            'google_books' => 2,
            'open_library' => 1,
        ];

        $leftSource = (string) ($left['source'] ?? '');
        $rightSource = (string) ($right['source'] ?? '');

        $preferLeft = ($priority[$leftSource] ?? 0) >= ($priority[$rightSource] ?? 0);

        $primary = $preferLeft ? $left : $right;
        $secondary = $preferLeft ? $right : $left;

        foreach ([
            'title',
            'author',
            'publisher',
            'description',
            'publishedDate',
            'language',
            'genre',
            'coverUrl',
            'isbn10',
            'isbn13',
            'metadataSource',
            'sourceBookId',
            'isbnDbBookId',
            'googleVolumeId',
            'openLibraryEditionId',
            'openLibraryWorkId',
        ] as $field) {
            if ($this->isBlank($primary[$field] ?? null) && !$this->isBlank($secondary[$field] ?? null)) {
                $primary[$field] = $secondary[$field];
            }
        }

        if ((int) ($primary['pages'] ?? 0) <= 0 && (int) ($secondary['pages'] ?? 0) > 0) {
            $primary['pages'] = (int) $secondary['pages'];
        }

        $primaryAuthors = is_array($primary['authors'] ?? null) ? $primary['authors'] : [];
        $secondaryAuthors = is_array($secondary['authors'] ?? null) ? $secondary['authors'] : [];

        $primary['authors'] = array_values(array_unique(array_filter(array_map(
            fn($v) => trim((string) $v),
            array_merge($primaryAuthors, $secondaryAuthors)
        ), fn(string $v): bool => $v !== '')));

        if ($this->isBlank($primary['author'] ?? null) && count($primary['authors']) > 0) {
            $primary['author'] = $primary['authors'][0];
        }

        $alternateSources = is_array($primary['alternateSources'] ?? null) ? $primary['alternateSources'] : [];

        $secondaryAlt = [
            'source' => $secondary['source'] ?? null,
            'sourceBookId' => $secondary['sourceBookId'] ?? null,
        ];

        if (!$this->isBlank($secondaryAlt['source'])) {
            $alternateSources[] = $secondaryAlt;
        }

        foreach ((array) ($secondary['alternateSources'] ?? []) as $alt) {
            if (is_array($alt) && !$this->isBlank($alt['source'] ?? null)) {
                $alternateSources[] = [
                    'source' => $alt['source'] ?? null,
                    'sourceBookId' => $alt['sourceBookId'] ?? null,
                ];
            }
        }

        $primary['alternateSources'] = array_values(array_unique($alternateSources, SORT_REGULAR));

        return $primary;
    }

    private function scoreAndSortItems(array $items, string $query): array
    {
        $scored = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $score = $this->qualityScore($item, $query);
            $item['qualityScore'] = $score;

            if ($score > 0) {
                $scored[] = $item;
            }
        }

        usort($scored, function (array $a, array $b): int {
            return ($b['qualityScore'] ?? 0) <=> ($a['qualityScore'] ?? 0);
        });

        return $scored;
    }

    private function qualityScore(array $item, string $query): int
    {
        $score = 0;

        $title = trim((string) ($item['title'] ?? ''));
        $author = trim((string) ($item['author'] ?? ''));
        $publisher = trim((string) ($item['publisher'] ?? ''));
        $description = trim((string) ($item['description'] ?? ''));
        $genre = trim((string) ($item['genre'] ?? ''));
        $language = trim((string) ($item['language'] ?? ''));
        $source = trim((string) ($item['source'] ?? ''));

        $normalizedQuery = $this->normalizeQuery($query);
        $normalizedTitle = $this->normalizeTextForCompare($title);
        $normalizedAuthor = $this->normalizeTextForCompare($author);
        $normalizedPublisher = $this->normalizeTextForCompare($publisher);
        $normalizedGenre = $this->normalizeTextForCompare($genre);
        $normalizedDescription = $this->normalizeTextForCompare($description);

        if ($title !== '') $score += 10;
        if ($author !== '') $score += 10;
        if ((int) ($item['pages'] ?? 0) > 0) $score += 5;
        if ($publisher !== '') $score += 4;
        if (trim((string) ($item['coverUrl'] ?? '')) !== '') $score += 8;
        if ($description !== '') $score += 8;
        if (trim((string) ($item['isbn13'] ?? '')) !== '' || trim((string) ($item['isbn10'] ?? '')) !== '') $score += 10;

        if ($source === 'isbndb') $score += 10;
        if ($source === 'google_books') $score += 6;
        if ($source === 'open_library') $score += 4;

        if ($normalizedTitle === $normalizedQuery) {
        $score += 35;
        } elseif ($normalizedTitle !== '' && str_contains($normalizedTitle, $normalizedQuery)) {
            $score += 22;
        } elseif ($this->tokenOverlapScore($normalizedQuery, $normalizedTitle) >= 0.8) {
            $score += 16;
        } elseif ($this->tokenOverlapScore($normalizedQuery, $normalizedTitle) >= 0.5) {
            $score += 8;
        }

        $score += $this->exactTitlePrecisionBonus($query, $title);
        $score -= $this->titleMismatchPenalty($query, $title);

        $expectedAuthor = $this->guessAuthorFromQuery($query);
        if ($expectedAuthor !== null) {
            $expectedAuthorNorm = $this->normalizeTextForCompare($expectedAuthor);

            if ($normalizedAuthor !== '' && str_contains($normalizedAuthor, $expectedAuthorNorm)) {
                $score += 30;
            } else {
                $score -= 18;
            }
        }

        if ($this->queryLooksFrench($query)) {
            if ($this->isFrenchishLanguage($language)) {
                $score += 18;
            } elseif ($language !== '') {
                $score -= 18;
            }

            if ($this->looksLikeFrenchPublisher($publisher)) {
                $score += 8;
            }
        }

        if ($description !== '') {
            if (str_contains($normalizedDescription, $normalizedQuery)) {
                $score += 8;
            }

            if ($expectedAuthor !== null && str_contains($normalizedDescription, $this->normalizeTextForCompare($expectedAuthor))) {
                $score += 6;
            }

            if ($this->looksLikeJunkDescription($normalizedDescription)) {
                $score -= 25;
            }
        }

        if ($this->looksLikeNoiseTitle($normalizedTitle)) {
            $score -= 40;
        }

        if ($this->looksLikeNoiseGenre($normalizedGenre)) {
            $score -= 20;
        }

        if ($this->looksLikeNoisePublisher($normalizedPublisher)) {
            $score -= 10;
        }

        if ($title === '' || $author === '') {
            $score -= 20;
        }

        return max(0, $score);
    }

    private function resultKey(array $item): string
    {
        $isbn13 = trim((string) ($item['isbn13'] ?? ''));
        if ($isbn13 !== '') {
            return 'isbn13:' . $isbn13;
        }

        $isbn10 = trim((string) ($item['isbn10'] ?? ''));
        if ($isbn10 !== '') {
            return 'isbn10:' . $isbn10;
        }

        return 'fallback:' .
            $this->normalizeTextForCompare((string) ($item['title'] ?? '')) . '|' .
            $this->normalizeTextForCompare((string) ($item['author'] ?? ''));
    }

    private function containsPrimarySource(array $items, string $source): bool
    {
        foreach ($items as $item) {
            if (($item['source'] ?? '') === $source) {
                return true;
            }
        }

        return false;
    }

    private function containsAlternateSource(array $items, string $source): bool
    {
        foreach ($items as $item) {
            foreach (($item['alternateSources'] ?? []) as $alt) {
                if (($alt['source'] ?? '') === $source) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeIsbnDbBook(array $book): ?array
    {
        $title = trim((string) ($book['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $authors = [];
        if (isset($book['authors']) && is_array($book['authors'])) {
            foreach ($book['authors'] as $author) {
                if (is_array($author)) {
                    $name = trim((string) ($author['name'] ?? ''));
                } else {
                    $name = trim((string) $author);
                }

                if ($name !== '') {
                    $authors[] = $name;
                }
            }
        }

        $authors = array_values(array_unique($authors));

        $cover = $book['image'] ?? ($book['image_url'] ?? ($book['cover'] ?? null));
        $descriptionRaw = $book['synopsis'] ?? ($book['overview'] ?? ($book['description'] ?? null));
        $description = TextSanitizer::htmlToText(is_string($descriptionRaw) ? $descriptionRaw : null);

        $publisher = null;
        if (isset($book['publisher']) && is_array($book['publisher'])) {
            $publisher = trim((string) ($book['publisher']['name'] ?? ''));
        } else {
            $publisher = trim((string) ($book['publisher'] ?? ''));
        }

        $publisher = $publisher !== '' ? $publisher : null;

        $language = $book['language'] ?? null;
        if (is_array($language)) {
            $language = $language['code'] ?? ($language['name'] ?? null);
        }
        $language = $this->nullIfBlank($language);

        $date = $this->nullIfBlank(
            $book['date_published']
                ?? ($book['date_published_text'] ?? ($book['publication_date'] ?? null))
        );

        $isbn13 = $this->normalizePossibleIsbn($book['isbn13'] ?? null, 13);
        $isbn10 = $this->normalizePossibleIsbn($book['isbn10'] ?? null, 10);

        $bookId = $this->nullIfBlank($book['book_id'] ?? null);
        $sourceBookId = $bookId ?? $isbn13 ?? $isbn10 ?? null;

        if ($sourceBookId === null) {
            return null;
        }

        return [
            'source' => 'isbndb',
            'sourceBookId' => $sourceBookId,
            'isbnDbBookId' => $bookId,
            'googleVolumeId' => null,
            'openLibraryEditionId' => null,
            'openLibraryWorkId' => null,
            'title' => $title,
            'author' => count($authors) > 0 ? $authors[0] : trim((string) ($book['author'] ?? '')),
            'authors' => $authors,
            'publisher' => $publisher,
            'pages' => isset($book['pages']) && is_numeric($book['pages']) ? (int) $book['pages'] : 0,
            'coverUrl' => $this->nullIfBlank($cover),
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'publishedDate' => $date,
            'description' => $description,
            'language' => $language,
            'genre' => $this->normalizeGenre($book['subjects'] ?? ($book['subject_ids'] ?? null)),
            'metadataSource' => 'isbndb',
            'alternateSources' => [],
            'qualityScore' => 0,
        ];
    }

    private function normalizeGoogleVolumeItem(array $it): ?array
    {
        $id = trim((string) ($it['id'] ?? ''));
        $info = $it['volumeInfo'] ?? [];

        $title = trim((string) ($info['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $authors = $info['authors'] ?? [];
        if (!is_array($authors)) {
            $authors = [];
        }

        $authors = array_values(array_filter(array_map(
            fn($v) => trim((string) $v),
            $authors
        ), fn(string $v): bool => $v !== ''));

        $publisher = $this->nullIfBlank($info['publisher'] ?? null);
        $pageCount = isset($info['pageCount']) ? (int) $info['pageCount'] : 0;

        $imageLinks = $info['imageLinks'] ?? [];
        $cover = $imageLinks['thumbnail'] ?? ($imageLinks['smallThumbnail'] ?? null);

        [$isbn10, $isbn13] = $this->extractGoogleIsbns($info['industryIdentifiers'] ?? []);

        return [
            'source' => 'google_books',
            'sourceBookId' => $id !== '' ? $id : ($isbn13 ?? ($isbn10 ?? $title)),
            'isbnDbBookId' => null,
            'googleVolumeId' => $id !== '' ? $id : null,
            'openLibraryEditionId' => null,
            'openLibraryWorkId' => null,
            'title' => $title,
            'author' => count($authors) > 0 ? $authors[0] : '',
            'authors' => $authors,
            'publisher' => $publisher,
            'pages' => $pageCount,
            'coverUrl' => $cover ? str_replace('http://', 'https://', (string) $cover) : null,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'publishedDate' => $this->nullIfBlank($info['publishedDate'] ?? null),
            'description' => TextSanitizer::htmlToText(is_string($info['description'] ?? null) ? $info['description'] : null),
            'language' => $this->nullIfBlank($info['language'] ?? null),
            'genre' => $this->normalizeGenre($info['categories'] ?? null),
            'metadataSource' => 'google_books',
            'alternateSources' => [],
            'qualityScore' => 0,
        ];
    }

    private function normalizeOpenLibraryDoc(array $doc): ?array
    {
        $title = trim((string) ($doc['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $editionId = $this->pickOpenLibraryEditionId($doc);
        if ($editionId === null || $editionId === '') {
            return null;
        }

        $authorNames = $doc['author_name'] ?? [];
        if (!is_array($authorNames)) {
            $authorNames = [];
        }

        $authors = array_values(array_filter(array_map(
            fn($v) => trim((string) $v),
            $authorNames
        ), fn(string $v): bool => $v !== ''));

        $publishers = $doc['publisher'] ?? [];
        if (!is_array($publishers)) {
            $publishers = [];
        }

        [$isbn10, $isbn13] = $this->extractOpenLibraryIsbns($doc['isbn'] ?? []);
        $workId = $this->extractOpenLibraryWorkId($doc);

        $coverUrl = null;
        $client = new OpenLibraryClient();
        if (isset($doc['cover_i']) && is_numeric($doc['cover_i'])) {
            $coverUrl = $client->coverUrlFromCoverId((int) $doc['cover_i'], 'L');
        } elseif ($editionId !== '') {
            $coverUrl = $client->coverUrlFromEdition($editionId, 'L');
        }

        return [
            'source' => 'open_library',
            'sourceBookId' => $editionId,
            'isbnDbBookId' => null,
            'googleVolumeId' => null,
            'openLibraryEditionId' => $editionId,
            'openLibraryWorkId' => $workId,
            'title' => $title,
            'author' => count($authors) > 0 ? $authors[0] : '',
            'authors' => $authors,
            'publisher' => count($publishers) > 0 ? trim((string) $publishers[0]) : null,
            'pages' => isset($doc['number_of_pages_median']) && is_numeric($doc['number_of_pages_median'])
                ? (int) $doc['number_of_pages_median']
                : 0,
            'coverUrl' => $coverUrl,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'publishedDate' => isset($doc['first_publish_year']) ? (string) $doc['first_publish_year'] : null,
            'description' => null,
            'language' => $this->normalizeOpenLibraryLanguage($doc['language'] ?? null),
            'genre' => $this->normalizeGenre($doc['subject'] ?? null),
            'metadataSource' => 'open_library',
            'alternateSources' => [],
            'qualityScore' => 0,
        ];
    }

    private function normalizeOpenLibraryEditionForImport(OpenLibraryClient $client, array $edition): array
    {
        $editionId = $this->extractOlid($edition['key'] ?? null, '/books/');
        $workId = null;
        $work = null;

        if (isset($edition['works'][0]['key'])) {
            $workId = $this->extractOlid($edition['works'][0]['key'], '/works/');
            if ($workId) {
                try {
                    $work = $client->getWork($workId);
                } catch (\Throwable $e) {
                    error_log('[BOOKLY][OpenLibrary work import] ' . $e->getMessage());
                }
            }
        }

        $authors = [];
        foreach (($edition['authors'] ?? []) as $authorRef) {
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
                    $authors[] = $name;
                }
            } catch (\Throwable $e) {
                error_log('[BOOKLY][OpenLibrary author import] ' . $e->getMessage());
            }
        }

        $publisher = null;
        if (isset($edition['publishers'][0])) {
            $publisher = trim((string) $edition['publishers'][0]) ?: null;
        }

        $isbn10 = isset($edition['isbn_10'][0]) ? $this->normalizePossibleIsbn($edition['isbn_10'][0], 10) : null;
        $isbn13 = isset($edition['isbn_13'][0]) ? $this->normalizePossibleIsbn($edition['isbn_13'][0], 13) : null;

        $coverUrl = null;
        if (isset($edition['covers'][0]) && is_numeric($edition['covers'][0])) {
            $coverUrl = $client->coverUrlFromCoverId((int) $edition['covers'][0], 'L');
        } elseif ($editionId) {
            $coverUrl = $client->coverUrlFromEdition($editionId, 'L');
        }

        $summary = $this->extractOpenLibraryDescription($edition);
        if ($summary === null && is_array($work)) {
            $summary = $this->extractOpenLibraryDescription($work);
        }

        $summary = TextSanitizer::htmlToText($summary);

        return [
            'source' => 'open_library',
            'sourceBookId' => $editionId ?? '',
            'isbnDbBookId' => null,
            'googleVolumeId' => null,
            'openLibraryEditionId' => $editionId,
            'openLibraryWorkId' => $workId,
            'title' => trim((string) ($edition['title'] ?? '')),
            'author' => count($authors) > 0 ? $authors[0] : '',
            'authors' => array_values(array_unique($authors)),
            'publisher' => $publisher,
            'pages' => isset($edition['number_of_pages']) && is_numeric($edition['number_of_pages'])
                ? (int) $edition['number_of_pages']
                : 0,
            'coverUrl' => $coverUrl,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'publishedDate' => isset($edition['publish_date']) ? trim((string) $edition['publish_date']) : null,
            'description' => $summary,
            'summary' => $summary,
            'language' => null,
            'genre' => $this->normalizeGenre(is_array($work) ? ($work['subjects'] ?? null) : null),
            'metadataSource' => 'open_library',
            'alternateSources' => [],
            'qualityScore' => 0,
        ];
    }

    private function extractGoogleIsbns($industryIdentifiers): array
    {
        $isbn10 = null;
        $isbn13 = null;

        if (!is_array($industryIdentifiers)) {
            return [$isbn10, $isbn13];
        }

        foreach ($industryIdentifiers as $id) {
            if (!is_array($id)) {
                continue;
            }

            $type = (string) ($id['type'] ?? '');
            $identifier = trim((string) ($id['identifier'] ?? ''));

            if ($type === 'ISBN_10' && $identifier !== '') {
                $isbn10 = $this->normalizePossibleIsbn($identifier, 10);
            }

            if ($type === 'ISBN_13' && $identifier !== '') {
                $isbn13 = $this->normalizePossibleIsbn($identifier, 13);
            }
        }

        return [$isbn10, $isbn13];
    }

    private function extractOpenLibraryIsbns($isbns): array
    {
        $isbn10 = null;
        $isbn13 = null;

        if (!is_array($isbns)) {
            return [$isbn10, $isbn13];
        }

        foreach ($isbns as $isbn) {
            $normalized10 = $this->normalizePossibleIsbn($isbn, 10);
            $normalized13 = $this->normalizePossibleIsbn($isbn, 13);

            if ($normalized10 !== null && $isbn10 === null) {
                $isbn10 = $normalized10;
            }

            if ($normalized13 !== null && $isbn13 === null) {
                $isbn13 = $normalized13;
            }

            if ($isbn10 !== null && $isbn13 !== null) {
                break;
            }
        }

        return [$isbn10, $isbn13];
    }

    private function pickOpenLibraryEditionId(array $doc): ?string
    {
        $editionKeys = $doc['edition_key'] ?? null;
        if (is_array($editionKeys)) {
            foreach ($editionKeys as $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    return $key;
                }
            }
        }

        $coverEditionKey = trim((string) ($doc['cover_edition_key'] ?? ''));
        if ($coverEditionKey !== '') {
            return $coverEditionKey;
        }

        $lendingEdition = trim((string) ($doc['lending_edition_s'] ?? ''));
        if ($lendingEdition !== '') {
            return $lendingEdition;
        }

        return null;
    }

    private function extractOpenLibraryWorkId(array $doc): ?string
    {
        $key = trim((string) ($doc['key'] ?? ''));
        if ($key !== '' && str_starts_with($key, '/works/')) {
            return $this->extractOlid($key, '/works/');
        }

        $seed = $doc['seed'] ?? null;
        if (is_array($seed)) {
            foreach ($seed as $s) {
                $s = trim((string) $s);
                if (str_starts_with($s, '/works/')) {
                    return $this->extractOlid($s, '/works/');
                }
            }
        }

        return null;
    }

    private function normalizeOpenLibraryLanguage(mixed $languages): ?string
    {
        if (!is_array($languages)) {
            return null;
        }

        foreach ($languages as $lang) {
            $s = trim((string) $lang);
            if ($s !== '') {
                return $s;
            }
        }

        return null;
    }

    private function extractOpenLibraryDescription(array $data): ?string
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

    private function normalizeGenre(mixed $raw): ?string
    {
        if (is_string($raw)) {
            $value = trim($raw);
            return $value !== '' ? mb_substr($value, 0, 120) : null;
        }

        if (!is_array($raw)) {
            return null;
        }

        foreach ($raw as $entry) {
            $value = trim((string) $entry);
            if ($value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return null;
    }

    private function normalizeQuery(string $q): string
    {
        $q = trim(mb_strtolower($q));
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        return $q;
    }

    private function normalizeTextForCompare(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['’', '\''], ' ', $text);
        $text = str_replace(['-', '_', '/', ':', ';', ',', '.', '(', ')', '[', ']'], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function tokenOverlapScore(string $query, string $target): float
    {
        if ($query === '' || $target === '') {
            return 0.0;
        }

        $qTokens = array_values(array_filter(explode(' ', $query), fn(string $t): bool => $t !== ''));
        $tTokens = array_values(array_filter(explode(' ', $target), fn(string $t): bool => $t !== ''));

        if (count($qTokens) === 0 || count($tTokens) === 0) {
            return 0.0;
        }

        $matches = 0;
        foreach ($qTokens as $qt) {
            if (in_array($qt, $tTokens, true)) {
                $matches++;
            }
        }

        return $matches / max(1, count($qTokens));
    }

    private function exactTitlePrecisionBonus(string $query, string $title): int
    {
        $q = $this->normalizeTextForCompare($query);
        $t = $this->normalizeTextForCompare($title);

        if ($q === '' || $t === '') {
            return 0;
        }

        if ($q === $t) {
            return 55;
        }

        if (str_starts_with($t, $q . ' ')) {
            return 30;
        }

        if (str_ends_with($t, ' ' . $q)) {
            return 24;
        }

        similar_text($q, $t, $percent);

        if ($percent >= 95) return 28;
        if ($percent >= 90) return 18;
        if ($percent >= 82) return 8;

        return 0;
    }

    private function titleMismatchPenalty(string $query, string $title): int
    {
        $q = $this->normalizeTextForCompare($query);
        $t = $this->normalizeTextForCompare($title);

        if ($q === '' || $t === '') {
            return 0;
        }

        if ($q === $t) {
            return 0;
        }

        // Cas métier important : l'étranger ne doit pas remonter l'étrange ...
        if ($q === 'l etranger' && str_starts_with($t, 'l etrange ')) {
            return 55;
        }

        // Si peu de similarité réelle malgré un petit chevauchement
        similar_text($q, $t, $percent);
        $overlap = $this->tokenOverlapScore($q, $t);

        if ($overlap >= 0.5 && $percent < 75) {
            return 22;
        }

        if ($overlap < 0.5 && $percent < 65) {
            return 30;
        }

        return 0;
    }

    private function queryLooksFrench(string $query): bool
    {
        $q = $this->normalizeTextForCompare($query);

        if (preg_match('/[éèêëàâäîïôöùûüç]/u', $query) === 1) {
            return true;
        }

        $markers = [
            ' le ', ' la ', ' les ', ' de ', ' du ', ' des ',
            ' l ', ' un ', ' une ', ' etranger', ' bel ami',
            ' ferme des animaux', ' petit prince',
        ];

        $padded = ' ' . $q . ' ';
        foreach ($markers as $marker) {
            if (str_contains($padded, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function guessAuthorFromQuery(string $query): ?string
    {
        $q = $this->normalizeTextForCompare($query);

        $map = [
            'bel ami' => 'maupassant',
            'bel-ami' => 'maupassant',
            'etranger' => 'camus',
            'l etranger' => 'camus',
            'madame bovary' => 'flaubert',
            'germinal' => 'zola',
            'le horla' => 'maupassant',
            'boule de suif' => 'maupassant',
            'tartuffe' => 'moliere',
            'le petit prince' => 'saint exupery',
        ];

        foreach ($map as $needle => $author) {
            if (str_contains($q, $needle)) {
                return $author;
            }
        }

        return null;
    }

    private function looksLikeNoiseTitle(string $title): bool
    {
        if ($title === '') {
            return false;
        }

        $needles = [
            'calendar',
            'kalender',
            'planner',
            'agenda',
            'coloring book',
            'colouring book',
            'workbook',
            'study guide',
            'summary of',
            'book review',
            'icons',
            'paradise found',
        ];

        foreach ($needles as $needle) {
            if (str_contains($title, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeNoiseGenre(string $genre): bool
    {
        if ($genre === '') {
            return false;
        }

        $needles = [
            'photography',
            'arts',
            'art',
            'crafts',
            'study aids',
            'business',
            'english',
            'college success',
            'education',
            'study guide',
        ];

        foreach ($needles as $needle) {
            if (str_contains($genre, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeNoisePublisher(string $publisher): bool
    {
        if ($publisher === '') {
            return false;
        }

        $needles = [
            'calendar',
            'poster',
        ];

        foreach ($needles as $needle) {
            if (str_contains($publisher, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeJunkDescription(string $description): bool
    {
        if ($description === '') {
            return false;
        }

        $needles = [
            'this is a softcover book',
            'in great shape',
            'fan favorite',
            'exclusive photo',
        ];

        foreach ($needles as $needle) {
            if (str_contains($description, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeFrenchPublisher(string $publisher): bool
    {
        $publisher = $this->normalizeTextForCompare($publisher);
        if ($publisher === '') {
            return false;
        }

        $needles = [
            'gallimard',
            'folio',
            'grasset',
            'larousse',
            'flammarion',
            'hachette',
            'albin michel',
            'bibebook',
            'livre de poche',
            'actes sud',
            'seuil',
            'robert laffont',
            'j ai lu',
        ];

        foreach ($needles as $needle) {
            if (str_contains($publisher, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isFrenchishLanguage(string $language): bool
    {
        $language = mb_strtolower(trim($language));
        return in_array($language, ['fr', 'fre', 'fra', 'french', 'français', 'francais'], true);
    }

    private function looksLikeIsbn(string $q): bool
    {
        $isbn = $this->normalizeIsbn($q);
        $len = strlen($isbn);
        return $len === 10 || $len === 13;
    }

    private function normalizeIsbn(string $q): string
    {
        return preg_replace('/[^0-9Xx]/', '', trim($q)) ?? '';
    }

    private function normalizePossibleIsbn(mixed $value, int $expectedLength): ?string
    {
        $normalized = preg_replace('/[^0-9Xx]/', '', trim((string) ($value ?? ''))) ?? '';
        return strlen($normalized) === $expectedLength ? $normalized : null;
    }

    private function guessIsbnDbColumn(string $query): ?string
    {
        if ($this->looksLikeIsbn($query)) {
            return null;
        }

        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $query) ?: [];
        $parts = array_values(array_filter($parts, fn(string $p): bool => trim($p) !== ''));

        if (count($parts) === 1 && preg_match('/^[\p{L}\p{N}\-\' ]+$/u', $query)) {
            return 'title';
        }

        return null;
    }

    private function isBlank(mixed $value): bool
    {
        return $this->nullIfBlank($value) === null;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}