<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Repositories\GoogleBooksCacheRepository;
use App\Repositories\IsbnDbCacheRepository;
use App\Repositories\OpenLibraryCacheRepository;

final class BookDiscoveryService
{
    public function search(string $query, int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));

        $primary = $this->searchIsbnDb($query, $limit);
        $fallbackGoogle = $this->searchGoogle($query, $limit);
        $fallbackOpen = $this->searchOpenLibrary($query, $limit);

        $merged = $this->mergeResults(
            $primary['items'] ?? [],
            $fallbackGoogle['items'] ?? [],
            $fallbackOpen['items'] ?? []
        );

        $responseItems = array_slice($merged, 0, $limit);

        return [
            'meta' => [
                'primarySource' => 'isbndb',
                'totalItems' => count($responseItems),
                'hasIsbnDbResults' => count($primary['items'] ?? []) > 0,
                'googleFallbackUsed' => $this->containsSource($responseItems, 'google_books'),
                'openLibraryFallbackUsed' => $this->containsSource($responseItems, 'open_library'),
            ],
            'items' => $responseItems,
        ];
    }

    public function fetchImportPayload(string $provider, string $providerBookId): array
    {
        $provider = trim($provider);
        $providerBookId = trim($providerBookId);

        if ($provider === 'isbndb') {
            $raw = (new IsbnDbClient())->getBook($providerBookId);
            $book = $raw['book'] ?? $raw;
            return $this->normalizeIsbnDbBook($book);
        }

        if ($provider === 'google_books') {
            $volume = (new GoogleBooksClient())->getVolume($providerBookId);
            return $this->normalizeGoogleVolumeForImport($volume);
        }

        if ($provider === 'open_library') {
            $client = new OpenLibraryClient();
            $edition = $client->getEdition($providerBookId);
            return $this->normalizeOpenLibraryEditionForImport($client, $edition);
        }

        throw new \InvalidArgumentException('Unsupported provider: ' . $provider);
    }

    private function searchIsbnDb(string $query, int $limit): array
    {
        $cacheTtl = (int) Env::get('ISBNDB_CACHE_TTL_SECONDS', '604800');
        $cacheKey = hash('sha256', json_encode([
            'provider' => 'isbndb',
            'q' => $this->normalizeQuery($query),
            'limit' => $limit,
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
                    $items[] = $this->normalizeIsbnDbBook($book);
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
        ], JSON_UNESCAPED_UNICODE));

        $cacheRepo = new GoogleBooksCacheRepository();
        $cached = $cacheRepo->getValid($cacheKey);

        if ($cached) {
            $payload = json_decode($cached['response_json'] ?? '[]', true) ?: [];
            return is_array($payload) ? $payload : ['items' => []];
        }

        $items = [];
        try {
            $raw = $this->looksLikeIsbn($query)
                ? (new GoogleBooksClient())->searchByIsbn($this->normalizeIsbn($query), $limit, $lang, $country)
                : (new GoogleBooksClient())->searchVolumes($query, $limit, $lang, $country);

            foreach (($raw['items'] ?? []) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $items[] = $this->normalizeGoogleVolumeItem($it);
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
        ], JSON_UNESCAPED_UNICODE));

        $cacheRepo = new OpenLibraryCacheRepository();
        $cached = $cacheRepo->getValid($cacheKey);

        if ($cached) {
            $payload = json_decode($cached['response_json'] ?? '[]', true) ?: [];
            return is_array($payload) ? $payload : ['items' => []];
        }

        $items = [];
        try {
            $raw = $this->looksLikeIsbn($query)
                ? (new OpenLibraryClient())->searchByIsbn($this->normalizeIsbn($query))
                : (new OpenLibraryClient())->searchBooks($query, $limit);

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

    private function mergeResults(array $isbndb, array $google, array $open): array
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
        usort($items, function (array $a, array $b): int {
            return $this->qualityScore($b) <=> $this->qualityScore($a);
        });

        return $items;
    }

    private function mergeTwo(array $left, array $right): array
    {
        $preferLeft = ($left['source'] ?? '') === 'isbndb';

        $merged = $preferLeft ? $left : $right;
        $secondary = $preferLeft ? $right : $left;

        foreach ([
            'title', 'author', 'publisher', 'description', 'publishedDate', 'language',
            'genre', 'coverUrl', 'isbn10', 'isbn13'
        ] as $field) {
            if (($merged[$field] ?? null) === null || trim((string) ($merged[$field] ?? '')) === '') {
                $merged[$field] = $secondary[$field] ?? null;
            }
        }

        if ((int) ($merged['pages'] ?? 0) <= 0 && (int) ($secondary['pages'] ?? 0) > 0) {
            $merged['pages'] = (int) $secondary['pages'];
        }

        $authors = array_merge(
            is_array($merged['authors'] ?? null) ? $merged['authors'] : [],
            is_array($secondary['authors'] ?? null) ? $secondary['authors'] : []
        );
        $authors = array_values(array_unique(array_filter(array_map('strval', $authors))));
        $merged['authors'] = $authors;

        $alternateSources = array_merge(
            is_array($merged['alternateSources'] ?? null) ? $merged['alternateSources'] : [],
            [[
                'source' => $secondary['source'] ?? 'unknown',
                'sourceBookId' => $secondary['sourceBookId'] ?? null,
            ]]
        );
        $merged['alternateSources'] = array_values(array_unique($alternateSources, SORT_REGULAR));

        $merged['qualityScore'] = $this->qualityScore($merged);

        return $merged;
    }

    private function qualityScore(array $item): int
    {
        $score = 0;

        if (trim((string) ($item['title'] ?? '')) !== '') $score += 3;
        if (trim((string) ($item['author'] ?? '')) !== '') $score += 3;
        if ((int) ($item['pages'] ?? 0) > 0) $score += 1;
        if (trim((string) ($item['publisher'] ?? '')) !== '') $score += 1;
        if (trim((string) ($item['coverUrl'] ?? '')) !== '') $score += 3;
        if (trim((string) ($item['description'] ?? '')) !== '') $score += 2;
        if (trim((string) ($item['isbn13'] ?? '')) !== '' || trim((string) ($item['isbn10'] ?? '')) !== '') $score += 3;
        if (($item['source'] ?? '') === 'isbndb') $score += 2;

        return $score;
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

        return 'fallback:' . mb_strtolower(trim((string) ($item['title'] ?? ''))) . '|' .
            mb_strtolower(trim((string) ($item['author'] ?? '')));
    }

    private function containsSource(array $items, string $source): bool
    {
        foreach ($items as $item) {
            if (($item['source'] ?? '') === $source) {
                return true;
            }

            foreach (($item['alternateSources'] ?? []) as $alt) {
                if (($alt['source'] ?? '') === $source) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeIsbnDbBook(array $book): array
    {
        $authors = [];
        if (isset($book['authors']) && is_array($book['authors'])) {
            foreach ($book['authors'] as $author) {
                if (is_array($author)) {
                    $name = trim((string) ($author['name'] ?? ''));
                    if ($name !== '') {
                        $authors[] = $name;
                    }
                } else {
                    $name = trim((string) $author);
                    if ($name !== '') {
                        $authors[] = $name;
                    }
                }
            }
        }

        $authors = array_values(array_unique($authors));

        $cover = $book['image'] ?? ($book['image_url'] ?? ($book['cover'] ?? null));
        $description = $book['synopsis'] ?? ($book['overview'] ?? ($book['description'] ?? null));
        $publisher = null;
        if (isset($book['publisher']) && is_array($book['publisher'])) {
            $publisher = $book['publisher']['name'] ?? null;
        } else {
            $publisher = $book['publisher'] ?? null;
        }

        $language = $book['language'] ?? null;
        if (is_array($language)) {
            $language = $language['code'] ?? ($language['name'] ?? null);
        }

        $date = $book['date_published'] ?? ($book['date_published_text'] ?? ($book['publication_date'] ?? null));
        $isbn13 = $book['isbn13'] ?? null;
        $isbn10 = $book['isbn10'] ?? null;

        return [
            'source' => 'isbndb',
            'sourceBookId' => (string) ($book['book_id'] ?? ($book['isbn13'] ?? ($book['isbn'] ?? ''))),
            'isbnDbBookId' => (string) ($book['book_id'] ?? ''),
            'googleVolumeId' => null,
            'openLibraryEditionId' => null,
            'openLibraryWorkId' => null,
            'title' => (string) ($book['title'] ?? ''),
            'author' => count($authors) > 0 ? $authors[0] : (string) ($book['author'] ?? ''),
            'authors' => $authors,
            'publisher' => is_string($publisher) ? trim($publisher) : null,
            'pages' => isset($book['pages']) && is_numeric($book['pages']) ? (int) $book['pages'] : 0,
            'coverUrl' => is_string($cover) && trim($cover) !== '' ? trim($cover) : null,
            'isbn10' => is_string($isbn10) && trim($isbn10) !== '' ? trim($isbn10) : null,
            'isbn13' => is_string($isbn13) && trim($isbn13) !== '' ? trim($isbn13) : null,
            'publishedDate' => is_string($date) && trim($date) !== '' ? trim($date) : null,
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            'language' => is_string($language) && trim($language) !== '' ? trim($language) : null,
            'genre' => $this->normalizeGenre($book['subjects'] ?? ($book['subject_ids'] ?? null)),
            'metadataSource' => 'isbndb',
            'alternateSources' => [],
            'qualityScore' => 0,
        ];
    }

    private function normalizeGoogleVolumeItem(array $it): array
    {
        $id = (string) ($it['id'] ?? '');
        $info = $it['volumeInfo'] ?? [];

        $authors = $info['authors'] ?? [];
        if (!is_array($authors)) {
            $authors = [];
        }

        $publisher = $info['publisher'] ?? null;
        $pageCount = isset($info['pageCount']) ? (int) $info['pageCount'] : 0;

        $imageLinks = $info['imageLinks'] ?? [];
        $cover = $imageLinks['thumbnail'] ?? ($imageLinks['smallThumbnail'] ?? null);

        [$isbn10, $isbn13] = $this->extractGoogleIsbns($info['industryIdentifiers'] ?? []);

        return [
            'source' => 'google_books',
            'sourceBookId' => $id,
            'isbnDbBookId' => null,
            'googleVolumeId' => $id,
            'openLibraryEditionId' => null,
            'openLibraryWorkId' => null,
            'title' => (string) ($info['title'] ?? ''),
            'author' => count($authors) > 0 ? (string) $authors[0] : '',
            'authors' => array_values(array_map('strval', $authors)),
            'publisher' => is_string($publisher) ? trim($publisher) : null,
            'pages' => $pageCount,
            'coverUrl' => $cover ? str_replace('http://', 'https://', (string) $cover) : null,
            'isbn10' => $isbn10,
            'isbn13' => $isbn13,
            'publishedDate' => $info['publishedDate'] ?? null,
            'description' => TextSanitizer::htmlToText($info['description'] ?? null),
            'language' => $info['language'] ?? null,
            'genre' => $this->normalizeGenre($info['categories'] ?? null),
            'metadataSource' => 'google_books',
            'alternateSources' => [],
            'qualityScore' => 0,
        ];
    }

    private function normalizeGoogleVolumeForImport(array $volume): array
    {
        $item = $this->normalizeGoogleVolumeItem($volume);
        return [
            ...$item,
            'summary' => $item['description'],
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
            'author' => count($authorNames) > 0 ? trim((string) $authorNames[0]) : '',
            'authors' => array_values(array_filter(array_map('strval', $authorNames))),
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

        $isbn10 = isset($edition['isbn_10'][0]) ? trim((string) $edition['isbn_10'][0]) : null;
        $isbn13 = isset($edition['isbn_13'][0]) ? trim((string) $edition['isbn_13'][0]) : null;

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

        return [
            'source' => 'open_library',
            'sourceBookId' => $editionId ?? '',
            'isbnDbBookId' => null,
            'googleVolumeId' => null,
            'openLibraryEditionId' => $editionId,
            'openLibraryWorkId' => $workId,
            'title' => (string) ($edition['title'] ?? ''),
            'author' => count($authors) > 0 ? (string) $authors[0] : '',
            'authors' => array_values(array_unique($authors)),
            'publisher' => $publisher,
            'pages' => isset($edition['number_of_pages']) && is_numeric($edition['number_of_pages'])
                ? (int) $edition['number_of_pages']
                : 0,
            'coverUrl' => $coverUrl,
            'isbn10' => $isbn10 ?: null,
            'isbn13' => $isbn13 ?: null,
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
                $isbn10 = $identifier;
            }

            if ($type === 'ISBN_13' && $identifier !== '') {
                $isbn13 = $identifier;
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
            $normalized = preg_replace('/[^0-9Xx]/', '', (string) $isbn) ?? '';
            $len = strlen($normalized);

            if ($len === 10 && $isbn10 === null) {
                $isbn10 = $normalized;
            }

            if ($len === 13 && $isbn13 === null) {
                $isbn13 = $normalized;
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
        $q = preg_replace('/\s+/', ' ', $q) ?? $q;
        return $q;
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
        if (count(array_values(array_filter($parts, fn(string $p): bool => trim($p) !== ''))) === 1 && preg_match('/^[\p{L}\p{N}\-\' ]+$/u', $query)) {
            return 'title';
        }

        return null;
    }
}
