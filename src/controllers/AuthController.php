<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Jwt;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use App\Services\ProgressService;

final class AuthController
{
    public function register(): void
    {
        $body = Request::json();

        $email = trim((string)($body['email'] ?? ''));
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if ($email === '' || $username === '' || $password === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'required' => ['email', 'username', 'password'],
            ], 'Missing required fields');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'field' => 'email',
            ], 'Invalid email');
        }

        if (strlen($password) < 8) {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'field' => 'password',
                'min' => 8,
            ], 'Password too short');
        }

        $repo = new UserRepository();

        if ($repo->findByEmail($email)) {
            throw new HttpException(409, 'CONFLICT', ['field' => 'email'], 'Email already used');
        }
        if ($repo->findByUsername($username)) {
            throw new HttpException(409, 'CONFLICT', ['field' => 'username'], 'Username already used');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $userId = $repo->create([
            'email' => $email,
            'username' => $username,
            'password_hash' => $hash,
        ]);

        $user = $repo->findById((int)$userId);
        if (!$user) {
            throw new HttpException(500, 'SERVER_ERROR', [], 'User not found after register');
        }

        [$accessToken, $refreshToken] = $this->issueTokens((int)$userId);

        Response::created([
            'user' => $this->publicUser($user),
            'tokens' => [
                'tokenType' => 'Bearer',
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'expiresIn' => (int)Env::get('JWT_ACCESS_TTL', '900'),
            ],
        ]);
    }

    public function login(): void
    {
        $body = Request::json();

        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if ($username === '' || $password === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'required' => ['username', 'password'],
            ], 'Missing required fields');
        }

        $repo = new UserRepository();
        $user = $repo->findByUsername($username);

        // on ne révèle pas si user existe
        if (!$user) {
            throw new HttpException(401, 'UNAUTHORIZED', [], 'Invalid credentials');
        }

        $hash = (string)($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new HttpException(401, 'UNAUTHORIZED', [], 'Invalid credentials');
        }

        [$accessToken, $refreshToken] = $this->issueTokens((int)$user['id']);

        Response::ok([
            'user' => $this->publicUser($user),
            'tokens' => [
                'tokenType' => 'Bearer',
                'accessToken' => $accessToken,
                'refreshToken' => $refreshToken,
                'expiresIn' => (int)Env::get('JWT_ACCESS_TTL', '900'),
            ],
        ]);
    }

    /**
     * Refresh access token (rotation refresh token)
     * Body: { "refreshToken": "..." }
     */
    public function refresh(): void
    {
        $body = Request::json();
        $refreshToken = trim((string)($body['refreshToken'] ?? ''));

        if ($refreshToken === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'required' => ['refreshToken'],
            ], 'refreshToken is required');
        }

        $refreshRepo = new RefreshTokenRepository();
        $userId = $refreshRepo->verify($refreshToken);

        if (!$userId) {
            throw new HttpException(401, 'INVALID_REFRESH', [], 'Invalid refresh token');
        }

        // Rotation : on révoque l'ancien, on émet un nouveau
        $refreshRepo->revoke($refreshToken);

        [$accessToken, $newRefreshToken] = $this->issueTokens($userId);

        Response::ok([
            'tokens' => [
                'tokenType' => 'Bearer',
                'accessToken' => $accessToken,
                'refreshToken' => $newRefreshToken,
                'expiresIn' => (int)Env::get('JWT_ACCESS_TTL', '900'),
            ],
        ]);
    }

    /**
     * Logout = revoke refresh token
     * Body: { "refreshToken": "..." }
     */
    public function logout(): void
    {
        $body = Request::json();
        $refreshToken = trim((string)($body['refreshToken'] ?? ''));

        if ($refreshToken === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'required' => ['refreshToken'],
            ], 'refreshToken is required');
        }

        $repo = new RefreshTokenRepository();
        $repo->revoke($refreshToken);

        Response::ok(['loggedOut' => true]);
    }

    public function me(): void
    {
        $uid = Auth::requireAuth();

        $repo = new UserRepository();
        $user = $repo->findById($uid);

        if (!$user) {
            throw new HttpException(401, 'UNAUTHORIZED', [], 'Unauthorized');
        }

        Response::ok([
            'user' => $this->publicUser($user),
        ]);
    }

    /**
     * (Optionnel) logout global de tous les appareils
     * - utile pour "Déconnexion partout"
     */
    public function logoutAll(): void
    {
        $uid = Auth::requireAuth();

        $repo = new RefreshTokenRepository();
        $count = $repo->revokeAllForUser($uid);

        Response::ok(['loggedOutAll' => true, 'revoked' => $count]);
    }

    // -------------------- Helpers --------------------

    private function issueTokens(int $userId): array
    {
        $accessToken = $this->issueAccessToken($userId);

        $refreshTtl = (int)Env::get('JWT_REFRESH_TTL', '2592000');
        $refreshToken = $this->issueRefreshToken();

        $refreshRepo = new RefreshTokenRepository();
        $refreshRepo->create($userId, $refreshToken, $refreshTtl);

        return [$accessToken, $refreshToken];
    }

    private function issueAccessToken(int $userId): string
    {
        $secret = Env::get('JWT_SECRET', '');
        $ttl = (int)Env::get('JWT_ACCESS_TTL', '900');

        if ($secret === '') {
            throw new HttpException(500, 'SERVER_MISCONFIG', [], 'JWT_SECRET is missing');
        }

        $now = time();

        $payload = [
            'iss' => 'bookly-api',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return Jwt::encode($payload, $secret);
    }

    private function issueRefreshToken(): string
    {
        // 64 chars hex
        return bin2hex(random_bytes(32));
    }

    private function publicUser(?array $u): array
    {
        if (!$u) return [];

        $goal = isset($u['goal_pages_per_day']) ? (int)$u['goal_pages_per_day'] : 20;
        $lang = isset($u['language']) ? (string)$u['language'] : 'FR';
        $density = isset($u['density']) ? (string)$u['density'] : 'Comfort';

        $progress = [
            'xp' => (int)($u['xp'] ?? 0),
            'level' => 1,
            'title' => 'Lecteur novice',
            'badges' => [],
        ];

        try {
            $ps = new ProgressService();
            $progress = $ps->snapshot((int)$u['id']);
        } catch (\Throwable $e) {
            // noop
        }

        return [
            'id' => (int)($u['id'] ?? 0),
            'email' => (string)($u['email'] ?? ''),
            'username' => (string)($u['username'] ?? ''),
            'firstName' => (string)($u['first_name'] ?? ''),
            'lastName' => (string)($u['last_name'] ?? ''),
            'bio' => $u['bio'] ?? null,

            'progress' => $progress,

            // compat old front
            'xp' => (int)($progress['xp'] ?? 0),
            'level' => (int)($progress['level'] ?? 1),
            'title' => (string)($progress['title'] ?? 'Lecteur novice'),
            'badges' => is_array($progress['badges'] ?? null) ? $progress['badges'] : [],

            'preferences' => [
                'goalPagesPerDay' => $goal,
                'language' => $lang,
                'density' => $density,
            ],
            'createdAt' => $u['created_at'] ?? null,
            'updatedAt' => $u['updated_at'] ?? null,
        ];
    }
}
