<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\HttpException;
use App\Repositories\CardRepository;

final class CardsController
{
    private CardRepository $repo;

    public function __construct()
    {
        $this->repo = new CardRepository();
    }

    /**
     * GET /v1/cards
     * Retourne toutes les cartes avec état (locked / unlocked / claimed)
     */
    public function index(): void
    {
        $userId = Auth::requireAuth();

        (new \App\Services\CardService())->checkUnlocks($userId);

        $cards = $this->repo->listForUser($userId);

        Response::ok([
            'cards' => $cards
        ]);
    }

    /**
     * POST /v1/cards/{id}/claim
     * Permet de claim une carte déjà débloquée
     */
    public function claim(array $params): void
    {
        $userId = Auth::requireAuth();

        if (!isset($params['id'])) {
            throw new HttpException('CARD_ID_REQUIRED', 'Card id missing', 400);
        }

        $cardId = (int)$params['id'];

        $card = $this->repo->findForUser($userId, $cardId);

        if (!$card) {
            throw new HttpException('CARD_NOT_FOUND', 'Card not found', 404);
        }

        if (!$card['unlocked']) {
            throw new HttpException('CARD_LOCKED', 'Card is still locked', 403);
        }

        if ($card['claimed']) {
            throw new HttpException('CARD_ALREADY_CLAIMED', 'Card already claimed', 400);
        }

        $this->repo->claim($userId, $cardId);

        Response::ok([
            'claimed' => true,
            'cardId' => $cardId
        ]);
    }
}