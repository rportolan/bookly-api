<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;

/**
 * Repository pour gérer les refresh tokens
 * 
 * Les refresh tokens permettent d'obtenir un nouveau JWT access token
 * sans se reconnecter. Ils sont stockés en base pour pouvoir être révoqués.
 */
final class RefreshTokenRepository
{
    /**
     * Crée un nouveau refresh token pour un utilisateur
     * 
     * @param int $userId L'ID de l'utilisateur
     * @param string $token Le refresh token (hash)
     * @param int $expiresIn Durée de validité en secondes (défaut: 90 jours)
     * @return void
     */
    public function create(int $userId, string $rawToken, int $expiresIn): void
    {
        $pdo = Db::pdo();
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

        $stmt = $pdo->prepare("
            INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
            VALUES (:user_id, :token_hash, :expires_at)
        ");

        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => $expiresAt,
        ]);
    }

    
    /**
     * Vérifie si un refresh token est valide
     * 
     * @param string $token Le refresh token
     * @return int|null L'ID utilisateur si valide, null sinon
     */
    public function verify(string $token): ?int
    {
        $pdo = Db::pdo();
        
        $stmt = $pdo->prepare("
            SELECT user_id, expires_at
            FROM refresh_tokens
            WHERE token_hash = :token_hash
              AND revoked_at IS NULL
            LIMIT 1
        ");
        
        $stmt->execute([
            'token_hash' => hash('sha256', $token),
        ]);
        
        $row = $stmt->fetch();
        
        if (!$row) {
            return null; // Token introuvable
        }
        
        // Vérifie l'expiration
        $expiresAt = strtotime($row['expires_at']);
        if ($expiresAt < time()) {
            return null; // Token expiré
        }
        
        return (int)$row['user_id'];
    }
    
    /**
     * Révoque un refresh token spécifique
     * 
     * @param string $token Le refresh token à révoquer
     * @return bool True si révoqué, false si introuvable
     */
    public function revoke(string $token): bool
    {
        $pdo = Db::pdo();
        
        $stmt = $pdo->prepare("
            UPDATE refresh_tokens
            SET revoked_at = NOW()
            WHERE token_hash = :token_hash
              AND revoked_at IS NULL
        ");
        
        $stmt->execute([
            'token_hash' => hash('sha256', $token),
        ]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Révoque tous les refresh tokens d'un utilisateur
     * Utile lors d'un logout complet ou changement de mot de passe
     * 
     * @param int $userId L'ID de l'utilisateur
     * @return int Nombre de tokens révoqués
     */
    public function revokeAllForUser(int $userId): int
    {
        $pdo = Db::pdo();
        
        $stmt = $pdo->prepare("
            UPDATE refresh_tokens
            SET revoked_at = NOW()
            WHERE user_id = :user_id
              AND revoked_at IS NULL
        ");
        
        $stmt->execute(['user_id' => $userId]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Nettoie les tokens expirés (à appeler périodiquement)
     * 
     * @return int Nombre de tokens supprimés
     */
    public function cleanExpired(): int
    {
        $pdo = Db::pdo();
        
        $stmt = $pdo->prepare("
            DELETE FROM refresh_tokens
            WHERE expires_at < NOW()
               OR revoked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        $stmt->execute();
        
        return $stmt->rowCount();
    }

    
}