<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\ChallengeRepository;
use App\Services\ProgressService;

final class ChallengesController
{
    // GET /challenges — dashboard widget : les 5 défis les plus proches d'aboutir
    public function index(): void
    {
        $uid  = Auth::requireAuth();
        $repo = new ChallengeRepository();

        $challenges = $repo->getActiveChallenges($uid);
        $this->autoComplete($uid, $repo, $challenges);

        // On ne garde que les défis non terminés
        $active = array_values(array_filter($challenges, fn($c) => !$c['completed']));

        // Tri par proximité d'achèvement (ratio de progression décroissant)
        usort($active, function ($a, $b) {
            $ratioA = $a['targetValue'] > 0 ? $a['currentProgress'] / $a['targetValue'] : 0;
            $ratioB = $b['targetValue'] > 0 ? $b['currentProgress'] / $b['targetValue'] : 0;
            if ($ratioA === $ratioB) {
                return $a['id'] <=> $b['id'];
            }
            return $ratioB <=> $ratioA;
        });

        // Top 5
        $top = array_slice($active, 0, 5);

        // Récompenses fraîchement débloquées (non encore vues) — pour
        // déclencher l'écran de félicitation directement depuis le dashboard
        $justCompleted = $repo->popJustCompleted($uid);

        Response::ok([
            'challenges'    => $top,
            'justCompleted' => $justCompleted,
        ]);
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
