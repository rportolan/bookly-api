<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

final class ExploreRepository
{
    /**
     * Returns all categories with their 10 most recently added books each.
     * Uses ROW_NUMBER() (MySQL 8+) to avoid N+1 queries.
     *
     * @return array<int, array{id: string, title: string, items: list<array>}>
     */
    public function allSections(): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->query("
            SELECT cat_id, cat_slug, cat_title, cat_sort,
                   eb_id, title, author, cover_url, description,
                   pages, genre, isbn13, isbn10, provider_book_id, source
            FROM (
                SELECT ec.id          AS cat_id,
                       ec.slug        AS cat_slug,
                       ec.title       AS cat_title,
                       ec.sort_order  AS cat_sort,
                       eb.id          AS eb_id,
                       eb.title, eb.author, eb.cover_url, eb.description,
                       eb.pages, eb.genre, eb.isbn13, eb.isbn10,
                       eb.provider_book_id, eb.source,
                       ROW_NUMBER() OVER (
                           PARTITION BY eb.category_id
                           ORDER BY eb.id DESC
                       ) AS rn
                FROM explore_books eb
                INNER JOIN explore_categories ec ON ec.id = eb.category_id
            ) ranked
            WHERE rn <= 10
            ORDER BY cat_sort ASC, eb_id ASC
        ");

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $sections  = [];
        $catMeta   = [];

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

            $sections[$catId][] = [
                'source'               => (string) $row['source'],
                'sourceBookId'         => $isbn13 ?? ('eb_' . (int) $row['eb_id']),
                'providerBookId'       => $row['provider_book_id'] !== null ? (string) $row['provider_book_id'] : null,
                'title'                => (string) $row['title'],
                'author'               => (string) $row['author'],
                'coverUrl'             => $row['cover_url'] !== null ? (string) $row['cover_url'] : null,
                'description'          => $row['description'] !== null ? (string) $row['description'] : null,
                'pages'                => (int) $row['pages'],
                'genre'                => $row['genre'] !== null ? (string) $row['genre'] : null,
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
}
