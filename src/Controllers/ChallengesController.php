<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\ChallengeRepository;
use App\Services\ProgressService;

final class ChallengesController
{
    // GET /challenges — dashboard widget (active weekly + monthly)
    public function index(): void
    {
        $uid  = Auth::requireAuth();
        $repo = new ChallengeRepository();

        $challenges = $repo->getActiveChallenges($uid);
        $this->autoComplete($uid, $repo, $challenges);

        Response::ok(['challenges' => $challenges]);
    }

    // GET /challenges/page — dedicated challenges screen
    public function page(): void
    {
        $uid  = Auth::requireAuth();
        $repo = new ChallengeRepository();

        $data = $repo->getPageData($uid);

        // Auto-complete si le dashboard n'a pas encore été appelé
        $this->autoComplete($uid, $repo, $data['active']);
        $data['active']    = array_values(array_filter($data['active'], fn($c) => !$c['completed']));

        // Récupère et consomme les récompenses non encore vues (seen = 0 → 1)
        $data['justCompleted'] = $repo->popJustCompleted($uid);

        Response::ok($data);
    }

    // -------------------------------------------------------------------------

    private function autoComplete(int $uid, ChallengeRepository $repo, array &$challenges): void
    {
        $progressService = new ProgressService();

        foreach ($challenges as &$challenge) {
            if (!$challenge['completed'] && $challenge['currentProgress'] >= $challenge['targetValue']) {
                $inserted = $repo->complete($uid, $challenge['id'], $challenge['xpReward']);
                if ($inserted) {
                    $progressService->award($uid, 'CHALLENGE_COMPLETED', $challenge['xpReward']);
                    $challenge['completed']   = true;
                    $challenge['completedAt'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                }
            }
        }
        unset($challenge);
    }
}
