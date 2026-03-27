<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\LearnRepository;
use App\Services\ProgressService;

final class LearnController
{
    public function __construct(
        private LearnRepository $repo = new LearnRepository()
    ) {}

    public function books(): void
    {
        $uid = Auth::requireAuth();
        $books = $this->repo->listBooksForLearn($uid);

        Response::ok(['books' => $books]);
    }

    public function deck(): void
    {
        $uid = Auth::requireAuth();

        $userBookId = (int)(Request::query('userBookId', '0') ?? '0');
        $mode = (string)(Request::query('mode', 'mix') ?? 'mix');
        $search = (string)(Request::query('search', '') ?? '');

        if ($userBookId < 0) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'userBookId'], 'Invalid userBookId');
        }

        $allowedModes = ['mix', 'vocab', 'quotes'];
        if (!in_array($mode, $allowedModes, true)) {
            $mode = 'mix';
        }

        $search = trim($search);
        if (mb_strlen($search) > 120) {
            $search = mb_substr($search, 0, 120);
        }

        $cards = ($userBookId === 0)
            ? $this->repo->deckForAllBooks($uid, $mode, $search)
            : $this->repo->deckForUserBook($uid, $userBookId, $mode, $search);

        Response::ok([
            'cards' => $cards,
            'count' => count($cards),
        ]);
    }

    public function completeSession(): void
    {
        $uid = Auth::requireAuth();
        $body = Request::json();

        $sessionId  = trim((string)($body['sessionId'] ?? ''));
        $userBookId = (int)($body['userBookId'] ?? 0);
        $mode       = (string)($body['mode'] ?? 'mix');

        $total = (int)($body['total'] ?? 0);
        $known = (int)($body['known'] ?? 0);
        $again = (int)($body['again'] ?? 0);

        $allowedModes = ['mix', 'vocab', 'quotes'];
        if (!in_array($mode, $allowedModes, true)) {
            $mode = 'mix';
        }

        $total = max(0, min(200, $total));
        $known = max(0, min(200, $known));
        $again = max(0, min(200, $again));

        if ($userBookId > 0 && !$this->repo->userOwnsUserBook($uid, $userBookId)) {
            throw new HttpException(403, 'FORBIDDEN', [], 'Forbidden');
        }

        $xp = ($total > 0) ? (5 + $known) : 0;
        if ($xp > 30) $xp = 30;

        $ps = new ProgressService();
        $before = $ps->snapshot($uid);
        $after = $before;

        if ($xp > 0) {
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
            }
        } else {
            $after['cardUnlock'] = $this->emptyCardUnlock();
        }

        $levelUp = $after['levelUp'] ?? $ps->buildLevelUpPayload($before, $after);

        Response::ok([
            'rewarded'  => ($xp > 0),
            'xpAwarded' => $xp,
            'progress'  => $this->onlyProgressSnapshot($after),
            'levelUp'   => $levelUp,
            'cardUnlock' => $after['cardUnlock'] ?? $this->emptyCardUnlock(),
            'awardedXp' => $xp,
            'awardType' => 'LEARN_SESSION_DONE',
        ]);
    }

    private function onlyProgressSnapshot(array $progress): array
    {
        return [
            'xp' => (int)($progress['xp'] ?? 0),
            'level' => (int)($progress['level'] ?? 1),
            'title' => (string)($progress['title'] ?? 'Lecteur novice'),
            'progressPct' => (int)($progress['progressPct'] ?? 0),
            'xpToNext' => (int)($progress['xpToNext'] ?? 0),
            'levelXp' => (int)($progress['levelXp'] ?? 0),
            'levelXpSpan' => (int)($progress['levelXpSpan'] ?? 1),
        ];
    }

    private function emptyCardUnlock(): array
    {
        return [
            'happened' => false,
            'cards' => [],
        ];
    }
}