<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class ExploreRepository
{
    /**
     * Returns all categories with their 10 most recently added books each.
     * Books are linked to categories via the explore_book_categories pivot
     * (many-to-many) — a single book can appear in several categories.
     *
     * @return array<int, array{id: string, title: string, items: list<array>}>
     */
    public function allSections(): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->query("
            SELECT cat_id, cat_slug, cat_title, cat_sort,
                   eb_id, title, author, cover_url, description, summary, why_read,
                   pages, genre, publication_year, language, difficulty,
                   isbn13, isbn10, provider_book_id, source
            FROM (
                SELECT ec.id          AS cat_id,
                       ec.slug        AS cat_slug,
                       ec.title       AS cat_title,
                       ec.sort_order  AS cat_sort,
                       eb.id          AS eb_id,
                       eb.title, eb.author, eb.cover_url, eb.description,
                       eb.summary, eb.why_read,
                       eb.pages, eb.genre, eb.publication_year,
                       eb.language, eb.difficulty,
                       eb.isbn13, eb.isbn10, eb.provider_book_id, eb.source,
                       ROW_NUMBER() OVER (
                           PARTITION BY ebc.category_id
                           ORDER BY ebc.sort_order ASC, eb.id DESC
                       ) AS rn
                FROM explore_book_categories ebc
                INNER JOIN explore_books eb       ON eb.id = ebc.book_id
                INNER JOIN explore_categories ec  ON ec.id = ebc.category_id
            ) ranked
            WHERE rn <= 10
            ORDER BY cat_sort ASC, eb_id ASC
        ");

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Tags par livre (une seule requête pour tous les livres affichés)
        $bookIds = array_values(array_unique(array_map(
            static fn ($r) => (int) $r['eb_id'],
            $rows
        )));
        $tagsByBook = $this->fetchTagsForBooks($bookIds);

        $sections = [];
        $catMeta  = [];

        foreach ($rows as $row) {
            $catId = (int) $row['cat_id'];

            if (!isset($catMeta[$catId])) {
                $catMeta[$catId] = [
                    'id'    => (string) $row['cat_slug'],
                    'title' => (string) $row['cat_title'],
                ];
                $sections[$catId] = [];
            }

            $isbn13 = $row['isbn13'] !== null ? (string) $row['isbn13'] : null;
            $bookId = (int) $row['eb_id'];

            $sections[$catId][] = [
                'source'               => (string) $row['source'],
                'sourceBookId'         => $isbn13 ?? ('eb_' . $bookId),
                'providerBookId'       => $row['provider_book_id'] !== null ? (string) $row['provider_book_id'] : null,
                'title'                => (string) $row['title'],
                'author'               => (string) $row['author'],
                'coverUrl'             => $row['cover_url'] !== null ? (string) $row['cover_url'] : null,
                'description'          => $row['description'] !== null ? (string) $row['description'] : null,
                'summary'              => $row['summary']  !== null ? (string) $row['summary']  : null,
                'whyRead'              => $row['why_read'] !== null ? (string) $row['why_read'] : null,
                'pages'                => (int) $row['pages'],
                'genre'                => $row['genre'] !== null ? (string) $row['genre'] : null,
                'publicationYear'      => $row['publication_year'] !== null ? (int) $row['publication_year'] : null,
                'language'             => (string) $row['language'],
                'difficulty'           => $row['difficulty'] !== null ? (string) $row['difficulty'] : null,
                'tags'                 => $tagsByBook[$bookId] ?? [],
                'isbn13'               => $isbn13,
                'isbn10'               => $row['isbn10'] !== null ? (string) $row['isbn10'] : null,
                'isbnDbBookId'         => null,
                'googleVolumeId'       => null,
                'openLibraryEditionId' => null,
            ];
        }

        $out = [];
        foreach ($catMeta as $catId => $meta) {
            if (!empty($sections[$catId])) {
                $out[] = array_merge($meta, ['items' => $sections[$catId]]);
            }
        }

        return $out;
    }

    /**
     * Fetches tags grouped by book id.
     *
     * @param  list<int> $bookIds
     * @return array<int, list<array{slug: string, label: string}>>
     */
    private function fetchTagsForBooks(array $bookIds): array
    {
        if (empty($bookIds)) {
            return [];
        }

        $pdo          = Db::pdo();
        $placeholders = implode(',', array_fill(0, count($bookIds), '?'));

        $stmt = $pdo->prepare("
            SELECT ebt.book_id, t.slug, t.label
            FROM explore_book_tags ebt
            INNER JOIN catalog_tags t ON t.id = ebt.tag_id
            WHERE ebt.book_id IN ($placeholders)
            ORDER BY t.label ASC
        ");
        $stmt->execute($bookIds);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['book_id']][] = [
                'slug'  => (string) $row['slug'],
                'label' => (string) $row['label'],
            ];
        }

        return $out;
    }
}
