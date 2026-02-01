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

    /**
     * GET /v1/learn/books
     * Return user's books for Learn page dropdown.
     */
    public function books(): void
    {
        $uid = Auth::requireAuth();
        $books = $this->repo->listBooksForLearn($uid);

        Response::ok(['books' => $books]);
    }

    /**
     * GET /v1/learn/deck?userBookId=123&mode=mix|vocab|quotes&search=...
     */
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
            // garde-fou simple (évite les payloads énormes)
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

    /**
     * POST /v1/learn/session/complete
     */
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

        // Normalisation mode
        $allowedModes = ['mix', 'vocab', 'quotes'];
        if (!in_array($mode, $allowedModes, true)) {
            $mode = 'mix';
        }

        // clamp
        $total = max(0, min(200, $total));
        $known = max(0, min(200, $known));
        $again = max(0, min(200, $again));

        // anti-cheat: vérifier ownership si userBookId ciblé
        if ($userBookId > 0 && !$this->repo->userOwnsUserBook($uid, $userBookId)) {
            throw new HttpException(403, 'FORBIDDEN', [], 'Forbidden');
        }

        // XP MVP
        $xp = ($total > 0) ? (5 + $known) : 0;
        if ($xp > 30) $xp = 30;

        $snapshot = null;

        if ($xp > 0) {
            try {
                $ps = new ProgressService();
                $snapshot = $ps->award($uid, 'LEARN_SESSION_DONE', $xp, [
                    'sessionId'  => $sessionId,
                    'userBookId' => $userBookId,
                    'mode'       => $mode,
                    'total'      => $total,
                    'known'      => $known,
                    'again'      => $again,
                ]);
            } catch (\Throwable $e) {
                error_log('[BOOKLY][XP] award LEARN_SESSION_DONE failed: ' . $e->getMessage());
            }
        }

        Response::ok([
            'rewarded'  => ($xp > 0),
            'xpAwarded' => $xp,
            'progress'  => $snapshot,
        ]);
    }
}
