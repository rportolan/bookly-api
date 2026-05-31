<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ExploreRepository;

/**
 * Serves curated book sections from the explore_categories / explore_books tables.
 *
 * Content is managed via SQL seed scripts (migrations/008_explore_seed.sql and
 * subsequent monthly batches). The 10 most recently added books per category
 * are returned; no live API calls at runtime.
 */
final class ExploreService
{
    public function allSections(): array
    {
        return (new ExploreRepository())->allSections();
    }
}
