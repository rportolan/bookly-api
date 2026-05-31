<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Response;
use App\Repositories\DictionaryCacheRepository;
use App\Services\WiktionaryClient;

final class DictionaryController
{
    public function lookup(): void
    {
        Auth::requireAuth();

        $term = trim((string)($_GET['term'] ?? ''));
        $term = $this->normalizeTerm($term);

        if ($term === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'term'], 'term is required');
        }
        if (mb_strlen($term) > 80) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'term'], 'term too long');
        }

        $lang  = 'fr';
        $cache = new DictionaryCacheRepository();
        try {
            $hit = $cache->getFresh($lang, $term);
        } catch (\Throwable $e) {
            error_log('[DICTIONARY] cache getFresh failed: ' . $e->getMessage());
            $hit = null;
        }

        if ($hit) {
            Response::ok($hit);
            return;
        }

        $client = new WiktionaryClient();
        $res    = $client->getDefinitionsFr($term);

        try {
            $ttl = (int)Env::get('DICTIONARY_CACHE_TTL_DAYS', '30');
            $cache->upsert($lang, $term, $res, $ttl);
        } catch (\Throwable $e) {
            error_log('[DICTIONARY] cache upsert failed: ' . $e->getMessage());
        }

        Response::ok($res);
    }

    private function normalizeTerm(string $term): string
    {
        $t = trim($term);
        $t = preg_replace('/\s+/u', ' ', $t ?? '') ?? '';
        return $t;
    }
}
