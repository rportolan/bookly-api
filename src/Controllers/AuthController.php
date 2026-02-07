<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Jwt;
use App\Core\Mailer;

use App\Repositories\EmailVerificationRepository;
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

        // ✅ Mieux que PASSWORD_DEFAULT : ARGON2ID si dispo
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algo);

        $userId = $repo->create([
            'email' => $email,
            'username' => $username,
            'password_hash' => $hash,
            'email_verified_at' => null,
        ]);

        $user = $repo->findById((int)$userId);
        if (!$user) {
            throw new HttpException(500, 'SERVER_ERROR', [], 'User not found after register');
        }

        // ✅ Crée token + envoie email
        $this->sendVerificationEmail((int)$userId, $email);

        Response::created([
            'user' => $this->publicUser($user),
            'verification' => [
                'sent' => true,
                'required' => true,
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

        if (!$user) {
            throw new HttpException(401, 'UNAUTHORIZED', [], 'Invalid credentials');
        }

        $hash = (string)($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            throw new HttpException(401, 'UNAUTHORIZED', [], 'Invalid credentials');
        }

        // ✅ Bloque tant que email non vérifié
        if (empty($user['email_verified_at'])) {
            throw new HttpException(403, 'EMAIL_NOT_VERIFIED', [
                'action' => 'resend_verification',
            ], 'Email not verified');
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
     * GET /v1/auth/verify-email?email=...&token=...
     */
    public function verifyEmail(): void
    {
        $email = trim((string)($_GET['email'] ?? ''));
        $token = trim((string)($_GET['token'] ?? ''));

        if ($email === '' || $token === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'required' => ['email', 'token'],
            ], 'Missing required fields');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'field' => 'email',
            ], 'Invalid email');
        }

        $tokenHash = hash('sha256', $token);

        $verifyRepo = new EmailVerificationRepository();
        $row = $verifyRepo->findValidByEmailAndTokenHash($email, $tokenHash);

        if (!$row) {
            throw new HttpException(400, 'INVALID_TOKEN', [], 'Invalid verification link');
        }

        if (!empty($row['used_at'])) {
            Response::ok([
                'verified' => true,
                'alreadyVerified' => true,
            ]);
            return;
        }

        $expiresAt = strtotime((string)$row['expires_at']);
        if ($expiresAt <= 0 || $expiresAt < time()) {
            throw new HttpException(400, 'TOKEN_EXPIRED', [], 'Verification link expired');
        }

        $userId = (int)$row['user_id'];

        $userRepo = new UserRepository();
        $user = $userRepo->findById($userId);
        if (!$user) {
            throw new HttpException(400, 'INVALID_TOKEN', [], 'Invalid verification link');
        }

        // Déjà vérifié ?
        if (!empty($user['email_verified_at'])) {
            $verifyRepo->markUsed($userId);
            Response::ok([
                'verified' => true,
                'alreadyVerified' => true,
            ]);
            return;
        }

        // ✅ Marque vérifié + token utilisé
        $userRepo->markEmailVerified($userId);
        $verifyRepo->markUsed($userId);

        Response::ok([
            'verified' => true,
            'alreadyVerified' => false,
        ]);
    }

    /**
     * POST /v1/auth/resend-verification
     * Body: { "email": "..." }
     *
     * Réponse volontairement générique (anti-enumération)
     */
    public function resendVerification(): void
    {
        $body = Request::json();
        $email = trim((string)($body['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // réponse générique volontaire
            Response::ok(['sent' => true]);
            return;
        }

        $userRepo = new UserRepository();
        $user = $userRepo->findByEmail($email);

        if (!$user) {
            // anti-enumération
            Response::ok(['sent' => true]);
            return;
        }

        // déjà vérifié => ok
        if (!empty($user['email_verified_at'])) {
            Response::ok(['sent' => true, 'alreadyVerified' => true]);
            return;
        }

        $userId = (int)$user['id'];
        $verifyRepo = new EmailVerificationRepository();

        // cooldown simple (anti spam)
        if (!$verifyRepo->canResend($userId, 60)) {
            throw new HttpException(429, 'TOO_MANY_REQUESTS', [
                'retryAfter' => 60,
            ], 'Please wait before resending');
        }

        $this->sendVerificationEmail($userId, $email);

        Response::ok(['sent' => true]);
    }

    /**
     * Refresh access token (rotation refresh token)
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

    public function logoutAll(): void
    {
        $uid = Auth::requireAuth();

        $repo = new RefreshTokenRepository();
        $count = $repo->revokeAllForUser($uid);

        Response::ok(['loggedOutAll' => true, 'revoked' => $count]);
    }

    // -------------------- Email verification helpers --------------------

    private function sendVerificationEmail(int $userId, string $email): void
    {
        $ttl = (int)Env::get('EMAIL_VERIFY_TTL', '3600');

        $rawToken = bin2hex(random_bytes(32)); // 64 chars
        $tokenHash = hash('sha256', $rawToken);

        $verifyRepo = new EmailVerificationRepository();
        $verifyRepo->upsertTokenForUser($userId, $tokenHash, $ttl);

        $appUrl = rtrim(Env::get('APP_URL', 'http://localhost:8080'), '/');
        $verifyUrl = $appUrl . "/v1/auth/verify-email?email=" . urlencode($email) . "&token=" . urlencode($rawToken);

        $subject = "Confirme ton email - Bookly";
        $html = "
            <div style=\"font-family:Arial,sans-serif;line-height:1.4\">
              <h2>Confirme ton email</h2>
              <p>Bienvenue sur Bookly 👋</p>
              <p>Clique sur ce bouton pour confirmer ton adresse email :</p>
              <p><a href=\"{$verifyUrl}\" style=\"display:inline-block;padding:12px 16px;text-decoration:none;border-radius:10px;background:#111827;color:#fff\">Confirmer mon email</a></p>
              <p style=\"color:#6b7280;font-size:12px\">Si le bouton ne marche pas, copie/colle ce lien :</p>
              <p style=\"color:#6b7280;font-size:12px\">{$verifyUrl}</p>
            </div>
        ";

        Mailer::send($email, $subject, $html);
    }

    // -------------------- JWT helpers --------------------

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
        return bin2hex(random_bytes(32));
    }

    private function publicUser(?array $u): array
    {
        if (!$u) return [];

        $goal = isset($u['goal_pages_per_day']) ? (int)$u['goal_pages_per_day'] : 20;
        $lang = isset($u['language']) ? (string)$u['language'] : 'FR';
        $density = isset($u['density']) ? (string)$u['density'] : 'Comfort';

        $isVerified = !empty($u['email_verified_at']);

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

            'emailVerified' => $isVerified,
            'emailVerifiedAt' => $u['email_verified_at'] ?? null,

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
