<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class BookRepository
{
    public function listForUser(int $userId, ?string $q, ?string $status): array
    {
        $pdo = Db::pdo();

        $sql = "
          SELECT
            ub.id AS id,
            b.id  AS bookId,
            b.google_volume_id,
            b.openlibrary_work_id,
            b.openlibrary_edition_id,
            b.isbndb_book_id,
            b.metadata_source,
            b.title, b.author, b.genre, b.pages, b.publisher,
            b.isbn10, b.isbn13, b.published_date, b.language, b.cover_url,
            ub.status, ub.progress_pages, ub.rating, ub.started_at, ub.finished_at,
            ub.summary, ub.analysis_work,
            ub.created_at, ub.updated_at
          FROM user_books ub
          JOIN books b ON b.id = ub.book_id
          WHERE ub.user_id = :user_id
        ";

        $params = ['user_id' => $userId];

        if ($status && $status !== 'all') {
            $sql .= " AND ub.status = :status";
            $params['status'] = $status;
        }

        if ($q && $q !== '') {
            $sql .= " AND (b.title LIKE :q OR b.author LIKE :q OR b.isbn13 LIKE :q OR b.isbn10 LIKE :q)";
            $params['q'] = '%' . $q . '%';
        }

        $sql .= " ORDER BY ub.updated_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findOneForUser(int $userId, int $userBookId): ?array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
          SELECT
            ub.id AS id,
            b.id  AS bookId,
            b.google_volume_id,
            b.openlibrary_work_id,
            b.openlibrary_edition_id,
            b.isbndb_book_id,
            b.metadata_source,
            b.title, b.author, b.genre, b.pages, b.publisher,
            b.isbn10, b.isbn13, b.published_date, b.language, b.cover_url,
            ub.status, ub.progress_pages, ub.rating, ub.started_at, ub.finished_at,
            ub.summary, ub.analysis_work,
            ub.created_at, ub.updated_at
          FROM user_books ub
          JOIN books b ON b.id = ub.book_id
          WHERE ub.user_id = :user_id AND ub.id = :id
          LIMIT 1
        ");

        $stmt->execute(['user_id' => $userId, 'id' => $userBookId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function userBookExists(int $userId, int $catalogBookId): bool
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT id
            FROM user_books
            WHERE user_id = :uid AND book_id = :bid
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId, 'bid' => $catalogBookId]);

        return (bool) $stmt->fetch();
    }

    public function createForUser(int $userId, array $payload): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
              INSERT INTO books (
                google_volume_id,
                openlibrary_work_id,
                openlibrary_edition_id,
                isbndb_book_id,
                metadata_source,
                title,
                author,
                genre,
                pages,
                publisher,
                isbn10,
                isbn13,
                published_date,
                language,
                cover_url
              )
              VALUES (
                :google_volume_id,
                :openlibrary_work_id,
                :openlibrary_edition_id,
                :isbndb_book_id,
                :metadata_source,
                :title,
                :author,
                :genre,
                :pages,
                :publisher,
                :isbn10,
                :isbn13,
                :published_date,
                :language,
                :cover_url
              )
            ");

            $stmt->execute([
                'google_volume_id' => $this->nullIfBlank($payload['googleVolumeId'] ?? null),
                'openlibrary_work_id' => $this->nullIfBlank($payload['openLibraryWorkId'] ?? null),
                'openlibrary_edition_id' => $this->nullIfBlank($payload['openLibraryEditionId'] ?? null),
                'isbndb_book_id' => $this->nullIfBlank($payload['isbnDbBookId'] ?? null),
                'metadata_source' => $this->normalizeMetadataSource($payload['metadataSource'] ?? 'manual'),
                'title' => trim((string) ($payload['title'] ?? '')),
                'author' => trim((string) ($payload['author'] ?? '')),
                'genre' => $this->nullIfBlank($payload['genre'] ?? null),
                'pages' => max(0, (int) ($payload['pages'] ?? 0)),
                'publisher' => $this->nullIfBlank($payload['publisher'] ?? null),
                'isbn10' => $this->nullIfBlank($payload['isbn10'] ?? null),
                'isbn13' => $this->nullIfBlank($payload['isbn13'] ?? null),
                'published_date' => $this->nullIfBlank($payload['publishedDate'] ?? null),
                'language' => $this->nullIfBlank($payload['language'] ?? null),
                'cover_url' => $this->nullIfBlank($payload['coverUrl'] ?? null),
            ]);

            $bookId = (int) $pdo->lastInsertId();

            if ($this->normalizeMetadataSource($payload['metadataSource'] ?? 'manual') === 'isbndb') {
                $this->touchIsbnDbSync($bookId);
            }

            $stmt = $pdo->prepare("
              INSERT INTO user_books
                (user_id, book_id, status, progress_pages, rating, started_at, finished_at, summary, analysis_work)
              VALUES
                (:user_id, :book_id, :status, :progress_pages, :rating, :started_at, :finished_at, :summary, :analysis_work)
            ");

            $stmt->execute([
                'user_id' => $userId,
                'book_id' => $bookId,
                'status' => $payload['status'] ?? 'À lire',
                'progress_pages' => max(0, (int) ($payload['progressPages'] ?? 0)),
                'rating' => isset($payload['rating']) ? $payload['rating'] : null,
                'started_at' => $payload['startedAt'] ?? null,
                'finished_at' => $payload['finishedAt'] ?? null,
                'summary' => $this->nullIfBlank($payload['summary'] ?? null),
                'analysis_work' => $this->nullIfBlank($payload['analysisWork'] ?? null),
            ]);

            $userBookId = (int) $pdo->lastInsertId();
            $pdo->commit();

            return $this->findOneForUser($userId, $userBookId) ?? [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function updateForUser(int $userId, int $userBookId, array $payload): ?array
    {
        $pdo = Db::pdo();

        $existing = $this->findOneForUser($userId, $userBookId);
        if (!$existing) {
            return null;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
              UPDATE books
              SET title = :title,
                  author = :author,
                  genre = :genre,
                  pages = :pages,
                  publisher = :publisher,
                  isbn10 = :isbn10,
                  isbn13 = :isbn13,
                  published_date = :published_date,
                  language = :language,
                  cover_url = :cover_url,
                  metadata_source = :metadata_source
              WHERE id = :book_id
            ");

            $stmt->execute([
                'title' => trim((string) ($payload['title'] ?? $existing['title'])),
                'author' => trim((string) ($payload['author'] ?? $existing['author'])),
                'genre' => $this->nullIfBlank($payload['genre'] ?? $existing['genre']),
                'pages' => isset($payload['pages']) ? max(0, (int) $payload['pages']) : (int) $existing['pages'],
                'publisher' => $this->nullIfBlank($payload['publisher'] ?? $existing['publisher']),
                'isbn10' => $this->nullIfBlank($payload['isbn10'] ?? $existing['isbn10']),
                'isbn13' => $this->nullIfBlank($payload['isbn13'] ?? $existing['isbn13']),
                'published_date' => $this->nullIfBlank($payload['publishedDate'] ?? $existing['published_date']),
                'language' => $this->nullIfBlank($payload['language'] ?? $existing['language']),
                'cover_url' => $this->nullIfBlank($payload['coverUrl'] ?? $existing['cover_url']),
                'metadata_source' => $this->normalizeMetadataSource($payload['metadataSource'] ?? ($existing['metadata_source'] ?? 'manual')),
                'book_id' => (int) $existing['bookId'],
            ]);

            $stmt = $pdo->prepare("
              UPDATE user_books
              SET status = :status,
                  progress_pages = :progress_pages,
                  rating = :rating,
                  started_at = :started_at,
                  finished_at = :finished_at,
                  summary = :summary,
                  analysis_work = :analysis_work
              WHERE id = :id AND user_id = :user_id
            ");

            $stmt->execute([
                'status' => $payload['status'] ?? $existing['status'],
                'progress_pages' => isset($payload['progressPages']) ? max(0, (int) $payload['progressPages']) : (int) $existing['progress_pages'],
                'rating' => array_key_exists('rating', $payload) ? $payload['rating'] : $existing['rating'],
                'started_at' => array_key_exists('startedAt', $payload) ? $payload['startedAt'] : $existing['started_at'],
                'finished_at' => array_key_exists('finishedAt', $payload) ? $payload['finishedAt'] : $existing['finished_at'],
                'summary' => array_key_exists('summary', $payload) ? $this->nullIfBlank($payload['summary']) : $existing['summary'],
                'analysis_work' => array_key_exists('analysisWork', $payload) ? $this->nullIfBlank($payload['analysisWork']) : $existing['analysis_work'],
                'id' => $userBookId,
                'user_id' => $userId,
            ]);

            $pdo->commit();
            return $this->findOneForUser($userId, $userBookId);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function deleteForUser(int $userId, int $userBookId): bool
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("DELETE FROM user_books WHERE id = :id AND user_id = :user_id");
        $stmt->execute(['id' => $userBookId, 'user_id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function updateProgress(int $userId, int $userBookId, int $progressPages, string $status): ?array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT ub.id, ub.user_id, b.pages
            FROM user_books ub
            JOIN books b ON b.id = ub.book_id
            WHERE ub.id = :ub_id AND ub.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['ub_id' => $userBookId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $pages = (int) $row['pages'];
        $safeProgress = max(0, $progressPages);
        if ($pages > 0) {
            $safeProgress = min($pages, $safeProgress);
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $setStartedAt = null;
        $setFinishedAt = null;

        if ($status === 'En cours') {
            $setStartedAt = $today;
            $setFinishedAt = null;
        } elseif ($status === 'Terminé') {
            $setStartedAt = $today;
            $setFinishedAt = $today;
            if ($pages > 0) {
                $safeProgress = $pages;
            }
        } else {
            $setFinishedAt = null;
        }

        $upd = $pdo->prepare("
            UPDATE user_books
            SET progress_pages = :pp,
                status = :st,
                started_at = COALESCE(started_at, :started_at),
                finished_at = :finished_at
            WHERE id = :ub_id AND user_id = :user_id
            LIMIT 1
        ");

        $upd->execute([
            'pp' => $safeProgress,
            'st' => $status,
            'started_at' => $setStartedAt,
            'finished_at' => $setFinishedAt,
            'ub_id' => $userBookId,
            'user_id' => $userId,
        ]);

        return $this->findOneForUser($userId, $userBookId);
    }

    public function findCatalogByGoogleVolumeId(string $googleVolumeId): ?array
    {
        $googleVolumeId = trim($googleVolumeId);
        if ($googleVolumeId === '') {
            return null;
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM books WHERE google_volume_id = :gid LIMIT 1");
        $stmt->execute(['gid' => $googleVolumeId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findCatalogByOpenLibraryEditionId(string $editionId): ?array
    {
        $editionId = trim($editionId);
        if ($editionId === '') {
            return null;
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM books WHERE openlibrary_edition_id = :eid LIMIT 1");
        $stmt->execute(['eid' => $editionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findCatalogByIsbnDbBookId(string $isbnDbBookId): ?array
    {
        $isbnDbBookId = trim($isbnDbBookId);
        if ($isbnDbBookId === '') {
            return null;
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM books WHERE isbndb_book_id = :bid LIMIT 1");
        $stmt->execute(['bid' => $isbnDbBookId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findCatalogByIsbn13(string $isbn13): ?array
    {
        $isbn13 = trim($isbn13);
        if ($isbn13 === '') {
            return null;
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM books WHERE isbn13 = :isbn13 LIMIT 1");
        $stmt->execute(['isbn13' => $isbn13]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findCatalogByIsbn10(string $isbn10): ?array
    {
        $isbn10 = trim($isbn10);
        if ($isbn10 === '') {
            return null;
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT * FROM books WHERE isbn10 = :isbn10 LIMIT 1");
        $stmt->execute(['isbn10' => $isbn10]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsertCatalogBookFromGoogle(array $payload): int
    {
        return $this->upsertCatalogBook($payload, 'google_books');
    }

    public function upsertCatalogBookFromOpenLibrary(array $payload): int
    {
        return $this->upsertCatalogBook($payload, 'open_library');
    }

    public function upsertCatalogBookFromIsbnDb(array $payload): int
    {
        return $this->upsertCatalogBook($payload, 'isbndb');
    }

    public function createUserBookIfNotExists(int $userId, int $catalogBookId, array $payload): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            SELECT id
            FROM user_books
            WHERE user_id = :uid AND book_id = :bid
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId, 'bid' => $catalogBookId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $userBookId = (int) $existing['id'];
            return $this->findOneForUser($userId, $userBookId) ?? [];
        }

        $stmt = $pdo->prepare("
            INSERT INTO user_books
              (user_id, book_id, status, progress_pages, rating, started_at, finished_at, summary, analysis_work)
            VALUES
              (:user_id, :book_id, :status, :progress_pages, :rating, :started_at, :finished_at, :summary, :analysis_work)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'book_id' => $catalogBookId,
            'status' => $payload['status'] ?? 'À lire',
            'progress_pages' => max(0, (int) ($payload['progressPages'] ?? 0)),
            'rating' => isset($payload['rating']) ? $payload['rating'] : null,
            'started_at' => $payload['startedAt'] ?? null,
            'finished_at' => $payload['finishedAt'] ?? null,
            'summary' => $this->nullIfBlank($payload['summary'] ?? null),
            'analysis_work' => $this->nullIfBlank($payload['analysisWork'] ?? null),
        ]);

        $userBookId = (int) $pdo->lastInsertId();
        return $this->findOneForUser($userId, $userBookId) ?? [];
    }

    public function upsertCatalogBook(array $payload, string $defaultSource = 'merged'): int
    {
        $normalizedPayload = $this->normalizeCatalogPayload($payload, $defaultSource);
        $existing = $this->findExistingCatalogBook($normalizedPayload);

        if ($existing) {
            $this->updateCatalogBook((int) $existing['id'], $normalizedPayload, $defaultSource);
            return (int) $existing['id'];
        }

        return $this->insertCatalogBook($normalizedPayload, $defaultSource);
    }

    private function findExistingCatalogBook(array $payload): ?array
    {
        $isbnDbBookId = trim((string) ($payload['isbnDbBookId'] ?? ''));
        if ($isbnDbBookId !== '') {
            $row = $this->findCatalogByIsbnDbBookId($isbnDbBookId);
            if ($row) {
                return $row;
            }
        }

        $googleVolumeId = trim((string) ($payload['googleVolumeId'] ?? ''));
        if ($googleVolumeId !== '') {
            $row = $this->findCatalogByGoogleVolumeId($googleVolumeId);
            if ($row) {
                return $row;
            }
        }

        $openLibraryEditionId = trim((string) ($payload['openLibraryEditionId'] ?? ''));
        if ($openLibraryEditionId !== '') {
            $row = $this->findCatalogByOpenLibraryEditionId($openLibraryEditionId);
            if ($row) {
                return $row;
            }
        }

        $isbn13 = trim((string) ($payload['isbn13'] ?? ''));
        if ($isbn13 !== '') {
            $row = $this->findCatalogByIsbn13($isbn13);
            if ($row) {
                return $row;
            }
        }

        $isbn10 = trim((string) ($payload['isbn10'] ?? ''));
        if ($isbn10 !== '') {
            $row = $this->findCatalogByIsbn10($isbn10);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    private function insertCatalogBook(array $payload, string $defaultSource): int
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            INSERT INTO books (
                google_volume_id,
                openlibrary_work_id,
                openlibrary_edition_id,
                isbndb_book_id,
                metadata_source,
                title,
                author,
                genre,
                pages,
                publisher,
                isbn10,
                isbn13,
                published_date,
                language,
                cover_url,
                isbndb_synced_at
            )
            VALUES (
                :gid,
                :owid,
                :oeid,
                :isbndb_bid,
                :metadata_source,
                :title,
                :author,
                :genre,
                :pages,
                :publisher,
                :isbn10,
                :isbn13,
                :published_date,
                :language,
                :cover,
                :isbndb_synced_at
            )
        ");
        $stmt->execute([
            'gid' => $payload['googleVolumeId'] ?? null,
            'owid' => $payload['openLibraryWorkId'] ?? null,
            'oeid' => $payload['openLibraryEditionId'] ?? null,
            'isbndb_bid' => $payload['isbnDbBookId'] ?? null,
            'metadata_source' => $payload['metadataSource'] ?? $defaultSource,
            'title' => $payload['title'] ?? '',
            'author' => $payload['author'] ?? '',
            'genre' => $payload['genre'] ?? null,
            'pages' => max(0, (int) ($payload['pages'] ?? 0)),
            'publisher' => $payload['publisher'] ?? null,
            'isbn10' => $payload['isbn10'] ?? null,
            'isbn13' => $payload['isbn13'] ?? null,
            'published_date' => $payload['publishedDate'] ?? null,
            'language' => $payload['language'] ?? null,
            'cover' => $payload['coverUrl'] ?? null,
            'isbndb_synced_at' => ($payload['metadataSource'] ?? $defaultSource) === 'isbndb'
                ? (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')
                : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function updateCatalogBook(int $bookId, array $payload, string $defaultSource): void
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            UPDATE books
            SET google_volume_id = COALESCE(google_volume_id, :gid),
                openlibrary_work_id = COALESCE(openlibrary_work_id, :owid),
                openlibrary_edition_id = COALESCE(openlibrary_edition_id, :oeid),
                isbndb_book_id = COALESCE(isbndb_book_id, :isbndb_bid),
                metadata_source = CASE
                    WHEN metadata_source IS NULL OR metadata_source = '' THEN :metadata_source
                    WHEN metadata_source = :metadata_source THEN metadata_source
                    ELSE 'merged'
                END,
                title = CASE WHEN NULLIF(:title, '') IS NOT NULL THEN :title ELSE title END,
                author = CASE WHEN NULLIF(:author, '') IS NOT NULL THEN :author ELSE author END,
                genre = COALESCE(NULLIF(:genre, ''), genre),
                pages = CASE WHEN :pages > 0 THEN :pages ELSE pages END,
                publisher = COALESCE(NULLIF(:publisher, ''), publisher),
                isbn10 = COALESCE(NULLIF(:isbn10, ''), isbn10),
                isbn13 = COALESCE(NULLIF(:isbn13, ''), isbn13),
                published_date = COALESCE(NULLIF(:published_date, ''), published_date),
                language = COALESCE(NULLIF(:language, ''), language),
                cover_url = COALESCE(NULLIF(:cover, ''), cover_url),
                isbndb_synced_at = CASE
                    WHEN :set_isbndb_sync = 1 THEN :isbndb_synced_at
                    ELSE isbndb_synced_at
                END
            WHERE id = :id
            LIMIT 1
        ");

        $metadataSource = $payload['metadataSource'] ?? $defaultSource;
        $setIsbnDbSync = $metadataSource === 'isbndb' ? 1 : 0;

        $stmt->execute([
            'gid' => $payload['googleVolumeId'] ?? null,
            'owid' => $payload['openLibraryWorkId'] ?? null,
            'oeid' => $payload['openLibraryEditionId'] ?? null,
            'isbndb_bid' => $payload['isbnDbBookId'] ?? null,
            'metadata_source' => $metadataSource,
            'title' => $payload['title'] ?? '',
            'author' => $payload['author'] ?? '',
            'genre' => $payload['genre'] ?? null,
            'pages' => max(0, (int) ($payload['pages'] ?? 0)),
            'publisher' => $payload['publisher'] ?? null,
            'isbn10' => $payload['isbn10'] ?? null,
            'isbn13' => $payload['isbn13'] ?? null,
            'published_date' => $payload['publishedDate'] ?? null,
            'language' => $payload['language'] ?? null,
            'cover' => $payload['coverUrl'] ?? null,
            'set_isbndb_sync' => $setIsbnDbSync,
            'isbndb_synced_at' => $setIsbnDbSync === 1
                ? (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s')
                : null,
            'id' => $bookId,
        ]);
    }

    private function touchIsbnDbSync(int $bookId): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            UPDATE books
            SET isbndb_synced_at = :synced_at
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            'synced_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'id' => $bookId,
        ]);
    }

    private function normalizeCatalogPayload(array $payload, string $defaultSource): array
    {
        return [
            'googleVolumeId' => $this->nullIfBlank($payload['googleVolumeId'] ?? null),
            'openLibraryWorkId' => $this->nullIfBlank($payload['openLibraryWorkId'] ?? null),
            'openLibraryEditionId' => $this->nullIfBlank($payload['openLibraryEditionId'] ?? null),
            'isbnDbBookId' => $this->nullIfBlank($payload['isbnDbBookId'] ?? null),
            'metadataSource' => $this->normalizeMetadataSource($payload['metadataSource'] ?? $defaultSource),
            'title' => trim((string) ($payload['title'] ?? '')),
            'author' => trim((string) ($payload['author'] ?? '')),
            'genre' => $this->nullIfBlank($payload['genre'] ?? null),
            'pages' => max(0, (int) ($payload['pages'] ?? 0)),
            'publisher' => $this->nullIfBlank($payload['publisher'] ?? null),
            'isbn10' => $this->nullIfBlank($payload['isbn10'] ?? null),
            'isbn13' => $this->nullIfBlank($payload['isbn13'] ?? null),
            'publishedDate' => $this->nullIfBlank($payload['publishedDate'] ?? null),
            'language' => $this->nullIfBlank($payload['language'] ?? null),
            'coverUrl' => $this->nullIfBlank($payload['coverUrl'] ?? null),
            'summary' => $this->nullIfBlank($payload['summary'] ?? null),
            'analysisWork' => $this->nullIfBlank($payload['analysisWork'] ?? null),
            'status' => $payload['status'] ?? 'À lire',
            'progressPages' => max(0, (int) ($payload['progressPages'] ?? 0)),
            'rating' => $payload['rating'] ?? null,
            'startedAt' => $payload['startedAt'] ?? null,
            'finishedAt' => $payload['finishedAt'] ?? null,
        ];
    }

    private function normalizeMetadataSource(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return 'manual';
        }

        $allowed = ['manual', 'google_books', 'open_library', 'isbndb', 'merged'];
        return in_array($value, $allowed, true) ? $value : 'manual';
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}