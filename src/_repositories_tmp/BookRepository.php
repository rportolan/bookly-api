<?php
namespace App\Repositories;

use App\Core\Db;

final class BookRepository
{
    public function listForUser(int $userId, ?string $q, ?string $status): array
    {
        $pdo = Db::pdo();

        $sql = "
          SELECT
            ub.id AS id,               -- <= ID utilisé dans /api/books/:id
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

    public function createForUser(int $userId, array $payload): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            // 1) insert into books (catalog)
            $stmt = $pdo->prepare("
              INSERT INTO books (title, author, genre, pages, publisher, cover_url)
              VALUES (:title, :author, :genre, :pages, :publisher, :cover_url)
            ");

            $stmt->execute([
                'title' => $payload['title'],
                'author' => $payload['author'],
                'genre' => $payload['genre'] ?? null,
                'pages' => (int)($payload['pages'] ?? 0),
                'publisher' => $payload['publisher'] ?? null,
                'cover_url' => $payload['coverUrl'] ?? null,
            ]);

            $bookId = (int)$pdo->lastInsertId();

            // 2) insert into user_books (user state)
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
                'progress_pages' => (int)($payload['progressPages'] ?? 0),
                'rating' => isset($payload['rating']) ? $payload['rating'] : null,
                'started_at' => $payload['startedAt'] ?? null,
                'finished_at' => $payload['finishedAt'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'analysis_work' => $payload['analysisWork'] ?? null,
            ]);

            $userBookId = (int)$pdo->lastInsertId();
            $pdo->commit();

            return $this->findOneForUser($userId, $userBookId);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function updateForUser(int $userId, int $userBookId, array $payload): ?array
    {
        $pdo = Db::pdo();

        // on récupère pour connaître book_id
        $existing = $this->findOneForUser($userId, $userBookId);
        if (!$existing) return null;

        $pdo->beginTransaction();
        try {
            // Update books table (title/author/genre/pages/publisher/cover_url)
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
                'pages' => isset($payload['pages']) ? (int)$payload['pages'] : (int)$existing['pages'],
                'publisher' => $payload['publisher'] ?? $existing['publisher'],
                'cover_url' => $payload['coverUrl'] ?? $existing['cover_url'],
                'book_id' => (int)$existing['bookId'],
            ]);

            // Update user_books table (status/progress/rating/dates/summary/analysis)
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
                'progress_pages' => isset($payload['progressPages']) ? (int)$payload['progressPages'] : (int)$existing['progress_pages'],
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

        // 1) récupérer pages + ownership
        $stmt = $pdo->prepare("
            SELECT ub.id, ub.user_id, b.pages
            FROM user_books ub
            JOIN books b ON b.id = ub.book_id
            WHERE ub.id = :ub_id AND ub.user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['ub_id' => $userBookId, 'user_id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) return null;

        $pages = (int)$row['pages'];
        $safeProgress = max(0, min($pages, $progressPages));

        // 2) dates (simple MVP)
        // - started_at : si on passe "En cours" et started_at NULL => today
        // - finished_at : si status "Terminé" => today, sinon NULL
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $setStartedAt = null;
        $setFinishedAt = null;

        if ($status === 'En cours') {
            $setStartedAt = $today;
            $setFinishedAt = null;
        } elseif ($status === 'Terminé') {
            $setStartedAt = $today;   // option: ou garder existant, mais MVP ok
            $setFinishedAt = $today;
            $safeProgress = $pages;   // terminé => 100%
        } else {
            // À lire / En pause / Abandonné
            $setFinishedAt = null;
        }

        // 3) update
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

        // 4) renvoyer l’objet comme ton show() le renvoie (id, title, pages, progressPages, status, ...)
        // Le plus simple : réutilise ta méthode existante qui fetch un book par user_book_id
        return $this->findOneForUser($userId, $userBookId); // adapte au nom chez toi
    }

}
