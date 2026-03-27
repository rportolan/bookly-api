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
            b.title, b.author, b.genre, b.pages, b.publisher, b.cover_url,
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
            $sql .= " AND (b.title LIKE :q OR b.author LIKE :q)";
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
            b.title, b.author, b.genre, b.pages, b.publisher, b.cover_url,
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
                title,
                author,
                genre,
                pages,
                publisher,
                isbn10,
                isbn13,
                cover_url
              )
              VALUES (
                :google_volume_id,
                :openlibrary_work_id,
                :openlibrary_edition_id,
                :title,
                :author,
                :genre,
                :pages,
                :publisher,
                :isbn10,
                :isbn13,
                :cover_url
              )
            ");

            $stmt->execute([
                'google_volume_id' => $payload['googleVolumeId'] ?? null,
                'openlibrary_work_id' => $payload['openLibraryWorkId'] ?? null,
                'openlibrary_edition_id' => $payload['openLibraryEditionId'] ?? null,
                'title' => $payload['title'],
                'author' => $payload['author'],
                'genre' => $payload['genre'] ?? null,
                'pages' => (int) ($payload['pages'] ?? 0),
                'publisher' => $payload['publisher'] ?? null,
                'isbn10' => $payload['isbn10'] ?? null,
                'isbn13' => $payload['isbn13'] ?? null,
                'cover_url' => $payload['coverUrl'] ?? null,
            ]);

            $bookId = (int) $pdo->lastInsertId();

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
                'progress_pages' => (int) ($payload['progressPages'] ?? 0),
                'rating' => isset($payload['rating']) ? $payload['rating'] : null,
                'started_at' => $payload['startedAt'] ?? null,
                'finished_at' => $payload['finishedAt'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'analysis_work' => $payload['analysisWork'] ?? null,
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
                  cover_url = :cover_url
              WHERE id = :book_id
            ");

            $stmt->execute([
                'title' => $payload['title'] ?? $existing['title'],
                'author' => $payload['author'] ?? $existing['author'],
                'genre' => $payload['genre'] ?? $existing['genre'],
                'pages' => isset($payload['pages']) ? (int) $payload['pages'] : (int) $existing['pages'],
                'publisher' => $payload['publisher'] ?? $existing['publisher'],
                'cover_url' => $payload['coverUrl'] ?? $existing['cover_url'],
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
                'progress_pages' => isset($payload['progressPages']) ? (int) $payload['progressPages'] : (int) $existing['progress_pages'],
                'rating' => array_key_exists('rating', $payload) ? $payload['rating'] : $existing['rating'],
                'started_at' => array_key_exists('startedAt', $payload) ? $payload['startedAt'] : $existing['started_at'],
                'finished_at' => array_key_exists('finishedAt', $payload) ? $payload['finishedAt'] : $existing['finished_at'],
                'summary' => array_key_exists('summary', $payload) ? $payload['summary'] : $existing['summary'],
                'analysis_work' => array_key_exists('analysisWork', $payload) ? $payload['analysisWork'] : $existing['analysis_work'],
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
        $safeProgress = max(0, min($pages, $progressPages));

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $setStartedAt = null;
        $setFinishedAt = null;

        if ($status === 'En cours') {
            $setStartedAt = $today;
            $setFinishedAt = null;
        } elseif ($status === 'Terminé') {
            $setStartedAt = $today;
            $setFinishedAt = $today;
            $safeProgress = $pages;
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
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT *
            FROM books
            WHERE google_volume_id = :gid
            LIMIT 1
        ");
        $stmt->execute(['gid' => $googleVolumeId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findCatalogByOpenLibraryEditionId(string $editionId): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT *
            FROM books
            WHERE openlibrary_edition_id = :eid
            LIMIT 1
        ");
        $stmt->execute(['eid' => $editionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findCatalogByIsbn13(string $isbn13): ?array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare("
            SELECT *
            FROM books
            WHERE isbn13 = :isbn13
            LIMIT 1
        ");
        $stmt->execute(['isbn13' => $isbn13]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function upsertCatalogBookFromGoogle(array $payload): int
    {
        $pdo = Db::pdo();

        $googleId = $payload['googleVolumeId'] ?? null;
        $isbn13 = $payload['isbn13'] ?? null;

        if ($googleId) {
            $existing = $this->findCatalogByGoogleVolumeId((string) $googleId);
            if ($existing) {
                $this->updateCatalogFromGoogle((int) $existing['id'], $payload);
                return (int) $existing['id'];
            }
        }

        if ($isbn13) {
            $existing = $this->findCatalogByIsbn13((string) $isbn13);
            if ($existing) {
                $this->updateCatalogFromGoogle((int) $existing['id'], $payload);
                return (int) $existing['id'];
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO books (
                google_volume_id,
                openlibrary_work_id,
                openlibrary_edition_id,
                title,
                author,
                genre,
                pages,
                publisher,
                isbn10,
                isbn13,
                cover_url
            )
            VALUES (
                :gid,
                :owid,
                :oeid,
                :title,
                :author,
                :genre,
                :pages,
                :publisher,
                :isbn10,
                :isbn13,
                :cover
            )
        ");
        $stmt->execute([
            'gid' => $googleId ?: null,
            'owid' => null,
            'oeid' => null,
            'title' => $payload['title'] ?? '',
            'author' => $payload['author'] ?? '',
            'genre' => $payload['genre'] ?? null,
            'pages' => (int) ($payload['pages'] ?? 0),
            'publisher' => $payload['publisher'] ?? null,
            'isbn10' => $payload['isbn10'] ?? null,
            'isbn13' => $payload['isbn13'] ?? null,
            'cover' => $payload['coverUrl'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function upsertCatalogBookFromOpenLibrary(array $payload): int
    {
        $pdo = Db::pdo();

        $editionId = $payload['openLibraryEditionId'] ?? null;
        $isbn13 = $payload['isbn13'] ?? null;

        if ($editionId) {
            $existing = $this->findCatalogByOpenLibraryEditionId((string) $editionId);
            if ($existing) {
                $this->updateCatalogFromOpenLibrary((int) $existing['id'], $payload);
                return (int) $existing['id'];
            }
        }

        if ($isbn13) {
            $existing = $this->findCatalogByIsbn13((string) $isbn13);
            if ($existing) {
                $this->updateCatalogFromOpenLibrary((int) $existing['id'], $payload);
                return (int) $existing['id'];
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO books (
                google_volume_id,
                openlibrary_work_id,
                openlibrary_edition_id,
                title,
                author,
                genre,
                pages,
                publisher,
                isbn10,
                isbn13,
                cover_url
            )
            VALUES (
                :gid,
                :owid,
                :oeid,
                :title,
                :author,
                :genre,
                :pages,
                :publisher,
                :isbn10,
                :isbn13,
                :cover
            )
        ");
        $stmt->execute([
            'gid' => null,
            'owid' => $payload['openLibraryWorkId'] ?? null,
            'oeid' => $editionId ?: null,
            'title' => $payload['title'] ?? '',
            'author' => $payload['author'] ?? '',
            'genre' => $payload['genre'] ?? null,
            'pages' => (int) ($payload['pages'] ?? 0),
            'publisher' => $payload['publisher'] ?? null,
            'isbn10' => $payload['isbn10'] ?? null,
            'isbn13' => $payload['isbn13'] ?? null,
            'cover' => $payload['coverUrl'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function updateCatalogFromGoogle(int $bookId, array $payload): void
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            UPDATE books
            SET google_volume_id = COALESCE(google_volume_id, :gid),
                title = COALESCE(NULLIF(:title,''), title),
                author = COALESCE(NULLIF(:author,''), author),
                genre = COALESCE(:genre, genre),
                pages = CASE WHEN :pages > 0 THEN :pages ELSE pages END,
                publisher = COALESCE(:publisher, publisher),
                isbn10 = COALESCE(:isbn10, isbn10),
                isbn13 = COALESCE(:isbn13, isbn13),
                cover_url = COALESCE(:cover, cover_url)
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'gid' => $payload['googleVolumeId'] ?? null,
            'title' => $payload['title'] ?? '',
            'author' => $payload['author'] ?? '',
            'genre' => $payload['genre'] ?? null,
            'pages' => (int) ($payload['pages'] ?? 0),
            'publisher' => $payload['publisher'] ?? null,
            'isbn10' => $payload['isbn10'] ?? null,
            'isbn13' => $payload['isbn13'] ?? null,
            'cover' => $payload['coverUrl'] ?? null,
            'id' => $bookId,
        ]);
    }

    private function updateCatalogFromOpenLibrary(int $bookId, array $payload): void
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare("
            UPDATE books
            SET openlibrary_work_id = COALESCE(openlibrary_work_id, :owid),
                openlibrary_edition_id = COALESCE(openlibrary_edition_id, :oeid),
                title = COALESCE(NULLIF(:title,''), title),
                author = COALESCE(NULLIF(:author,''), author),
                genre = COALESCE(:genre, genre),
                pages = CASE WHEN :pages > 0 THEN :pages ELSE pages END,
                publisher = COALESCE(:publisher, publisher),
                isbn10 = COALESCE(:isbn10, isbn10),
                isbn13 = COALESCE(:isbn13, isbn13),
                cover_url = COALESCE(:cover, cover_url)
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'owid' => $payload['openLibraryWorkId'] ?? null,
            'oeid' => $payload['openLibraryEditionId'] ?? null,
            'title' => $payload['title'] ?? '',
            'author' => $payload['author'] ?? '',
            'genre' => $payload['genre'] ?? null,
            'pages' => (int) ($payload['pages'] ?? 0),
            'publisher' => $payload['publisher'] ?? null,
            'isbn10' => $payload['isbn10'] ?? null,
            'isbn13' => $payload['isbn13'] ?? null,
            'cover' => $payload['coverUrl'] ?? null,
            'id' => $bookId,
        ]);
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
            'progress_pages' => (int) ($payload['progressPages'] ?? 0),
            'rating' => isset($payload['rating']) ? $payload['rating'] : null,
            'started_at' => $payload['startedAt'] ?? null,
            'finished_at' => $payload['finishedAt'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'analysis_work' => $payload['analysisWork'] ?? null,
        ]);

        $userBookId = (int) $pdo->lastInsertId();
        return $this->findOneForUser($userId, $userBookId) ?? [];
    }
}