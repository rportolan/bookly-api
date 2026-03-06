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
        Auth::requireAuth(); // évite que n’importe qui spam ton API

        $term = trim((string)($_GET['term'] ?? ''));
        $term = $this->normalizeTerm($term);

        if ($term === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'term'], 'term is required');
        }
        if (mb_strlen($term) > 80) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'term'], 'term too long');
        }

        $lang = 'fr';

        $cache = new DictionaryCacheRepository();
        $hit = $cache->getFresh($lang, $term);

        if ($hit) {
            Response::ok([
                'term' => (string)$hit['term'],
                'definition' => (string)$hit['definition'],
            ]);
            return;
        }

        $client = new WiktionaryClient();
        $res = $client->getDefinitionFr($term);

        $ttl = (int)Env::get('DICTIONARY_CACHE_TTL_DAYS', '30');
        $cache->upsert($lang, $term, $res['definition'], $ttl);

        Response::ok($res);
    }

    private function normalizeTerm(string $term): string
    {
        $t = trim($term);
        $t = preg_replace('/\s+/u', ' ', $t ?? '') ?? '';
        // On garde accents, apostrophes, tirets, etc.
        return $t;
    }
}