<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\LearnProgressRepository;
use App\Repositories\LearnRepository;
use App\Repositories\ProgressRepository;
use App\Services\ProgressService;

final class LearnController
{
    public function __construct(
        private LearnRepository         $repo     = new LearnRepository(),
        private LearnProgressRepository $progress = new LearnProgressRepository()
    ) {}

    public function books(): void
    {
        $uid = Auth::requireAuth();
        Response::ok(['books' => $this->repo->listBooksForLearn($uid)]);
    }

    public function stats(): void
    {
        $uid = Auth::requireAuth();
        Response::ok($this->progress->stats($uid));
    }

    public function deck(): void
    {
        $uid = Auth::requireAuth();

        $userBookIds = $this->parseBookIds(Request::query('userBookIds', '0') ?? '0');
        $mode        = (string)(Request::query('mode', 'mix') ?? 'mix');
        $typesParam  = (string)(Request::query('types', '') ?? '');
        $search      = trim((string)(Request::query('search', '') ?? ''));

        if (mb_strlen($search) > 120) {
            $search = mb_substr($search, 0, 120);
        }

        if ($typesParam !== '') {
            $types = $this->parseTypes($typesParam);
            $cards = $this->repo->buildDeck($uid, $userBookIds, $types);
        } else {
            $allowedModes = ['mix', 'vocab', 'quotes', 'characters', 'all'];
            if (!in_array($mode, $allowedModes, true)) $mode = 'mix';

            // Legacy mode path — treat as all books or single book
            $singleId = count($userBookIds) === 1 ? $userBookIds[0] : 0;
            $cards = ($singleId === 0)
                ? $this->repo->deckForAllBooks($uid, $mode, $search)
                : $this->repo->deckForUserBook($uid, $singleId, $mode, $search);
        }

        Response::ok(['cards' => $cards, 'count' => count($cards)]);
    }

    public function quiz(): void
    {
        $uid = Auth::requireAuth();

        $userBookIds = $this->parseBookIds(Request::query('userBookIds', '0') ?? '0');
        $typesParam  = (string)(Request::query('types', 'vocab,characters') ?? 'vocab,characters');
        $limit       = (int)(Request::query('limit', '10') ?? '10');
        $limit       = max(1, min(50, $limit));

        $types     = $this->parseTypes($typesParam);
        $questions = $this->repo->generateQuiz($uid, $userBookIds, $types, $limit);

        Response::ok(['questions' => $questions, 'count' => count($questions)]);
    }

    public function updateProgress(): void
    {
        $uid  = Auth::requireAuth();
        $body = Request::json();

        $results = $body['results'] ?? [];
        if (!is_array($results)) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'results'], 'results must be an array');
        }

        $updated = $this->progress->bulkUpdate($uid, $results);

        Response::ok(['updated' => $updated]);
    }

    public function completeSession(): void
    {
        $uid  = Auth::requireAuth();
        $body = Request::json();

        $sessionId  = trim((string)($body['sessionId'] ?? ''));
        $userBookId = (int)($body['userBookId'] ?? 0);
        $mode       = (string)($body['mode'] ?? 'mix');

        $total = max(0, min(200, (int)($body['total'] ?? 0)));
        $known = max(0, min(200, (int)($body['known'] ?? 0)));
        $again = max(0, min(200, (int)($body['again'] ?? 0)));

        // Optional per-item results for progress tracking
        $results = $body['results'] ?? [];
        if (is_array($results) && !empty($results)) {
            $this->progress->bulkUpdate($uid, $results);
        }

        $allowedModes = ['mix', 'vocab', 'quotes', 'characters', 'all'];
        if (!in_array($mode, $allowedModes, true)) $mode = 'mix';

        if ($userBookId > 0 && !$this->repo->userOwnsUserBook($uid, $userBookId)) {
            throw new HttpException(403, 'FORBIDDEN', [], 'Forbidden');
        }

        $xp = ($total > 0) ? (5 + $known) : 0;
        if ($xp > 30) $xp = 30;

        $ps     = new ProgressService();
        $before = $ps->snapshot($uid);
        $after  = $before;

        if ($xp > 0) {
            $alreadyRewarded = false;
            if ($sessionId !== '') {
                $pr = new ProgressRepository();
                $alreadyRewarded = $pr->hasEventMeta($uid, 'LEARN_SESSION_DONE', 'sessionId', $sessionId);
            }

            if ($alreadyRewarded) {
                $after = $ps->snapshot($uid);
                $after['cardUnlock'] = $this->emptyCardUnlock();
                $xp = 0;
            } else {
                try {
                    $after = $ps->award($uid, 'LEARN_SESSION_DONE', $xp, [
                        'sessionId'  => $sessionId,
                        'userBookId' => $userBookId,
                        'mode'       => $mode,
                        'total'      => $total,
                        'known'      => $known,
                        'again'      => $again,
                    ]);
                } catch (\Throwable $e) {
                    error_log('[BOOKLY][XP] award LEARN_SESSION_DONE failed: ' . $e->getMessage());
                    $after = $ps->snapshot($uid);
                    $after['cardUnlock'] = $this->emptyCardUnlock();
                    $xp = 0;
                }
            }
        } else {
            $after['cardUnlock'] = $this->emptyCardUnlock();
        }

        $levelUp = $after['levelUp'] ?? $ps->buildLevelUpPayload($before, $after);

        Response::ok([
            'rewarded'   => ($xp > 0),
            'xpAwarded'  => $xp,
            'progress'   => $this->onlyProgressSnapshot($after),
            'levelUp'    => $levelUp,
            'cardUnlock' => $after['cardUnlock'] ?? $this->emptyCardUnlock(),
            'awardedXp'  => $xp,
            'awardType'  => 'LEARN_SESSION_DONE',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Parse "1,2,3" or "0" into an array of positive int IDs.
     * "0" or empty → [] (meaning all books).
     */
    private function parseBookIds(string $param): array
    {
        $ids = [];
        foreach (explode(',', $param) as $part) {
            $id = (int)trim($part);
            if ($id > 0) $ids[] = $id;
        }
        return array_unique($ids);
    }

    private function parseTypes(string $param): array
    {
        $allowed = ['vocab', 'quotes', 'characters'];
        $parts   = explode(',', $param);
        $types   = [];
        foreach ($parts as $p) {
            $p = trim(strtolower($p));
            if (in_array($p, $allowed, true)) {
                $types[] = $p;
            }
        }
        return array_unique($types) ?: ['vocab'];
    }

    private function onlyProgressSnapshot(array $progress): array
    {
        return [
            'xp'           => (int)($progress['xp'] ?? 0),
            'level'        => (int)($progress['level'] ?? 1),
            'title'        => (string)($progress['title'] ?? 'Lecteur novice'),
            'progressPct'  => (int)($progress['progressPct'] ?? 0),
            'xpToNext'     => (int)($progress['xpToNext'] ?? 0),
            'levelXp'      => (int)($progress['levelXp'] ?? 0),
            'levelXpSpan'  => (int)($progress['levelXpSpan'] ?? 1),
        ];
    }

    private function emptyCardUnlock(): array
    {
        return ['happened' => false, 'cards' => []];
    }
}
