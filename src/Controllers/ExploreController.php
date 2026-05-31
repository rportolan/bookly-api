<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RecommendationRepository;
use App\Services\BookDiscoveryService;
use App\Services\ExploreService;

final class ExploreController
{

    public function sections(): void
    {
        $uid      = Auth::requireAuth();
        $sections = [];

        // ── Coup de cœur du mois ──────────────────────────────────────────────
        $month = (new \DateTimeImmutable('today'))->format('Y-m');
        $recs  = (new RecommendationRepository())->listByMonth($month);
        if (!empty($recs)) {
            $sections[] = [
                'id'    => 'recommendations',
                'title' => 'Coup de cœur du mois',
                'items' => array_map([$this, 'mapRec'], $recs),
            ];
        }

        // ── Curated sections from JSON ─────────────────────────────────────────
        $explore  = new ExploreService();
        $curated  = $explore->allSections();
        $sections = array_merge($sections, $curated);

        Response::ok(['sections' => $sections]);
    }

    public function search(): void
    {
        Auth::requireAuth();

        $q = trim((string) Request::query('q', ''));
        if ($q === '') {
            Response::ok(['items' => []]);
            return;
        }

        $service = new BookDiscoveryService();
        $result  = $service->search($q, 15);
        $items   = array_map([$this, 'mapBook'], $result['items'] ?? []);

        Response::ok(['items' => $items]);
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function mapRec(array $r): array
    {
        return [
            'source'               => 'recommendation',
            'sourceBookId'         => 'rec_' . (int)$r['id'],
            'providerBookId'       => null,
            'title'                => (string)$r['title'],
            'author'               => (string)$r['author'],
            'coverUrl'             => $r['coverUrl'] ?? null,
            'description'          => null,
            'pages'                => 0,
            'genre'                => null,
            'isbn13'               => null,
            'isbn10'               => null,
            'isbnDbBookId'         => null,
            'googleVolumeId'       => null,
            'openLibraryEditionId' => null,
        ];
    }

    private function mapBook(array $item): array
    {
        $source = (string)($item['source'] ?? '');
        $providerBookId = match ($source) {
            'isbndb'       => $item['isbnDbBookId'] ?? $item['isbn13'] ?? $item['isbn10'] ?? null,
            'google_books' => $item['googleVolumeId'] ?? null,
            'open_library' => $item['openLibraryEditionId'] ?? null,
            default        => $item['sourceBookId'] ?? null,
        };

        return [
            'source'               => $source,
            'sourceBookId'         => (string)($item['sourceBookId'] ?? ''),
            'providerBookId'       => $providerBookId,
            'title'                => (string)($item['title'] ?? ''),
            'author'               => (string)($item['author'] ?? ''),
            'coverUrl'             => $item['coverUrl'] ?? null,
            'description'          => $item['description'] ?? null,
            'pages'                => (int)($item['pages'] ?? 0),
            'genre'                => $item['genre'] ?? null,
            'isbn13'               => $item['isbn13'] ?? null,
            'isbn10'               => $item['isbn10'] ?? null,
            'isbnDbBookId'         => $item['isbnDbBookId'] ?? null,
            'googleVolumeId'       => $item['googleVolumeId'] ?? null,
            'openLibraryEditionId' => $item['openLibraryEditionId'] ?? null,
        ];
    }
}
