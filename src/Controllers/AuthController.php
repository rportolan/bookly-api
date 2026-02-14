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
use App\Repositories\PasswordResetRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;

use App\Services\ProgressService;

final class AuthController
{
    /* ============================================================
       REGISTER / LOGIN
    ============================================================ */

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

        // send verification email
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

    /* ============================================================
       EMAIL VERIFICATION
    ============================================================ */

    /**
     * GET /v1/auth/verify-email?email=...&token=...
     */
    public function verifyEmail(): void
    {
        $email = trim((string)($_GET['email'] ?? ''));
        $token = trim((string)($_GET['token'] ?? ''));

        if ($email === '' || $token === '') {
            $this->renderSimpleHtml("Lien invalide.", false, "Bookly - Vérification");
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->renderSimpleHtml("Adresse email invalide.", false, "Bookly - Vérification");
            return;
        }

        $tokenHash = hash('sha256', $token);

        $verifyRepo = new EmailVerificationRepository();
        $row = $verifyRepo->findValidByEmailAndTokenHash($email, $tokenHash);

        if (!$row) {
            $this->renderSimpleHtml("Lien invalide ou expiré.", false, "Bookly - Vérification");
            return;
        }

        if (!empty($row['used_at'])) {
            $this->renderSimpleHtml("Ton email est déjà vérifié.", true, "Bookly - Vérification");
            return;
        }

        $expiresAt = strtotime((string)$row['expires_at']);
        if ($expiresAt <= 0 || $expiresAt < time()) {
            $this->renderSimpleHtml("Lien expiré.", false, "Bookly - Vérification");
            return;
        }

        $userId = (int)$row['user_id'];

        $userRepo = new UserRepository();
        $userRepo->markEmailVerified($userId);
        $verifyRepo->markUsed($userId);

        $this->renderSimpleHtml("Email vérifié avec succès ! 🎉", true, "Bookly - Vérification");
    }

    /**
     * POST /v1/auth/resend-verification
     * Body: { "email": "..." }
     */
    public function resendVerification(): void
    {
        $body = Request::json();
        $email = trim((string)($body['email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::ok(['sent' => true]);
            return;
        }

        $userRepo = new UserRepository();
        $user = $userRepo->findByEmail($email);

        if (!$user) {
            Response::ok(['sent' => true]);
            return;
        }

        if (!empty($user['email_verified_at'])) {
            Response::ok(['sent' => true, 'alreadyVerified' => true]);
            return;
        }

        $userId = (int)$user['id'];
        $verifyRepo = new EmailVerificationRepository();

        if (!$verifyRepo->canResend($userId, 60)) {
            throw new HttpException(429, 'TOO_MANY_REQUESTS', [
                'retryAfter' => 60,
            ], 'Please wait before resending');
        }

        $this->sendVerificationEmail($userId, $email);

        Response::ok(['sent' => true]);
    }

    /* ============================================================
       FORGOT PASSWORD / RESET PASSWORD
    ============================================================ */

    /**
     * POST /v1/auth/forgot-password
     * Body: { "email": "..." }
     * Réponse générique (anti-enumération)
     */
    public function forgotPassword(): void
    {
        $body = Request::json();
        $email = trim((string)($body['email'] ?? ''));

        // anti-enumération
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::ok(['sent' => true]);
            return;
        }

        $userRepo = new UserRepository();
        $user = $userRepo->findByEmail($email);

        if (!$user) {
            Response::ok(['sent' => true]);
            return;
        }

        $userId = (int)$user['id'];
        $resetRepo = new PasswordResetRepository();

        $cooldown = (int)Env::get('PASSWORD_RESET_COOLDOWN', '60');
        if (!$resetRepo->canResend($userId, $cooldown)) {
            throw new HttpException(429, 'TOO_MANY_REQUESTS', [
                'retryAfter' => $cooldown,
            ], 'Please wait before resending');
        }

        $this->sendPasswordResetEmail($userId, $email);

        Response::ok(['sent' => true]);
    }

    /**
     * GET /v1/auth/reset-password?email=...&token=...
     * Fallback HTML form (GET ONLY)
     */
    public function resetPasswordHtml(): void
    {
        $email = trim((string)($_GET['email'] ?? ''));
        $token = trim((string)($_GET['token'] ?? ''));

        header('Content-Type: text/html; charset=utf-8');

        if ($email === '' || $token === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo $this->resetHtmlPage("Lien invalide.", false, false, $email, $token);
            return;
        }

        echo $this->resetHtmlPage("Choisis un nouveau mot de passe.", true, true, $email, $token);
    }

    /**
     * POST /v1/auth/reset-password
     * - Mobile => JSON: { email, token, newPassword }
     * - HTML   => FORM: email, token, new_password
     */
    public function resetPassword(): void
    {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        $isJson = str_contains($contentType, 'application/json');

        if ($isJson) {
            $body = Request::json();

            $email = trim((string)($body['email'] ?? ''));
            $token = trim((string)($body['token'] ?? ''));
            $newPassword = (string)($body['newPassword'] ?? '');

            $this->resetPasswordCore($email, $token, $newPassword);

            Response::ok(['reset' => true]);
            return;
        }

        // HTML form submit
        $email = trim((string)($_POST['email'] ?? ''));
        $token = trim((string)($_POST['token'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');

        header('Content-Type: text/html; charset=utf-8');

        try {
            $this->resetPasswordCore($email, $token, $newPassword);
            echo $this->resetHtmlPage("Mot de passe mis à jour ✅ Tu peux te reconnecter.", true, false, $email, $token);
            return;
        } catch (\Throwable $e) {
            $msg = "Lien invalide ou expiré.";
            if ($e instanceof HttpException) {
                $msg = match ($e->errorCode) {
                    'RESET_EXPIRED' => "Lien expiré.",
                    'RESET_ALREADY_USED' => "Lien déjà utilisé.",
                    'VALIDATION_ERROR' => "Mot de passe invalide (min 8).",
                    default => "Lien invalide ou expiré.",
                };
            }
            echo $this->resetHtmlPage($msg, false, true, $email, $token);
            return;
        }
    }

    private function resetPasswordCore(string $email, string $token, string $newPassword): void
    {
        if ($email === '' || $token === '' || $newPassword === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'required' => ['email', 'token', 'newPassword'],
            ], 'Missing required fields');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'email'], 'Invalid email');
        }

        if (strlen($newPassword) < 8) {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'field' => 'newPassword',
                'min' => 8,
            ], 'Password too short');
        }

        $tokenHash = hash('sha256', $token);

        $resetRepo = new PasswordResetRepository();
        $row = $resetRepo->findValidByEmailAndTokenHash($email, $tokenHash);

        if (!$row) {
            throw new HttpException(400, 'INVALID_RESET_LINK', [], 'Invalid or expired link');
        }

        if (!empty($row['used_at'])) {
            throw new HttpException(400, 'RESET_ALREADY_USED', [], 'Link already used');
        }

        $expiresAt = strtotime((string)$row['expires_at']);
        if ($expiresAt <= 0 || $expiresAt < time()) {
            throw new HttpException(400, 'RESET_EXPIRED', [], 'Link expired');
        }

        $userId = (int)$row['user_id'];

        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($newPassword, $algo);

        $userRepo = new UserRepository();
        $userRepo->updatePasswordHash($userId, $hash);

        $resetRepo->markUsed($userId);

        $rt = new RefreshTokenRepository();
        $rt->revokeAllForUser($userId);
    }

    private function resetHtmlPage(
        string $message,
        bool $ok,
        bool $showForm,
        string $email = '',
        string $token = ''
    ): string {
        $color = $ok ? "#16a34a" : "#dc2626";
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        $form = $showForm ? "
          <form method='POST' action='/v1/auth/reset-password' style='margin-top:18px;display:flex;flex-direction:column;gap:10px;'>
            <input type='hidden' name='email' value='{$safeEmail}' />
            <input type='hidden' name='token' value='{$safeToken}' />

            <input type='password' name='new_password' placeholder='Nouveau mot de passe (min 8)'
              style='padding:12px;border-radius:12px;border:1px solid #374151;background:#0b1220;color:#fff' />

            <button type='submit'
              style='padding:12px;border-radius:12px;border:1px solid #374151;background:#111827;color:#fff;font-weight:800'>
              Mettre à jour
            </button>

            <p style='margin-top:10px;color:#9ca3af;font-size:12px'>
              Email: {$safeEmail}
            </p>
          </form>
        " : "
          <p style='margin-top:16px;color:#9ca3af;'>Retourne dans l’app et connecte-toi.</p>
        ";

        return "
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset='UTF-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
          <title>Bookly - Reset password</title>
        </head>
        <body style='margin:0;font-family:Arial;background:#111827;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;'>
          <div style='text-align:center;padding:24px;max-width:420px;'>
            <h1 style='color:$color;'>$message</h1>
            $form
            <p style='margin-top:18px;font-size:12px;color:#6b7280;'>
              Si tu n’es pas à l’origine de cette demande, tu peux ignorer cette page.
            </p>
          </div>
        </body>
        </html>
        ";
    }

    /* ============================================================
       REFRESH / LOGOUT / ME
    ============================================================ */

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

    /* ============================================================
       HELPERS: MAILS
    ============================================================ */

    private function sendVerificationEmail(int $userId, string $email): void
    {
        $ttl = (int)Env::get('EMAIL_VERIFY_TTL', '3600');

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $verifyRepo = new EmailVerificationRepository();
        $verifyRepo->upsertTokenForUser($userId, $tokenHash, $ttl);

        $appUrl = rtrim((string)Env::get('APP_URL', 'http://localhost:8080'), '/');
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

    private function sendPasswordResetEmail(int $userId, string $email): void
    {
        $ttl = (int)Env::get('PASSWORD_RESET_TTL', '3600');

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $resetRepo = new PasswordResetRepository();
        $resetRepo->upsertTokenForUser($userId, $tokenHash, $ttl);

        $appUrl = rtrim((string)Env::get('APP_URL', 'http://localhost:8080'), '/');
        $resetUrl = $appUrl . "/v1/auth/reset-password?email=" . urlencode($email) . "&token=" . urlencode($rawToken);

        $subject = "Réinitialise ton mot de passe - Bookly";
        $html = "
            <div style=\"font-family:Arial,sans-serif;line-height:1.4\">
              <h2>Réinitialisation du mot de passe</h2>
              <p>Tu as demandé à réinitialiser ton mot de passe.</p>
              <p><a href=\"{$resetUrl}\" style=\"display:inline-block;padding:12px 16px;text-decoration:none;border-radius:10px;background:#111827;color:#fff\">Réinitialiser</a></p>
              <p style=\"color:#6b7280;font-size:12px\">Si tu n’es pas à l’origine de la demande, ignore cet email.</p>
              <p style=\"color:#6b7280;font-size:12px\">Lien (si le bouton ne marche pas) :</p>
              <p style=\"color:#6b7280;font-size:12px\">{$resetUrl}</p>
            </div>
        ";

        Mailer::send($email, $subject, $html);
    }

    /* ============================================================
       HELPERS: HTML RENDER
    ============================================================ */

    private function renderSimpleHtml(string $message, bool $success, string $title): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $color = $success ? "#16a34a" : "#dc2626";
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        echo "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>{$safeTitle}</title>
        </head>
        <body style='margin:0;font-family:Arial;background:#111827;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;'>
            <div style='text-align:center;padding:24px;max-width:420px;'>
                <h1 style='color:$color;'>$message</h1>
                <p style='color:#9ca3af;margin-top:16px;'>
                    Tu peux maintenant retourner dans l'application et te connecter.
                </p>
                <p style='margin-top:24px;font-size:12px;color:#6b7280;'>
                    Si l'application ne s'ouvre pas automatiquement, retourne simplement à Bookly.
                </p>
            </div>
        </body>
        </html>
        ";
    }

    /* ============================================================
       JWT HELPERS
    ============================================================ */

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
        $secret = (string)Env::get('JWT_SECRET', '');
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

    /* ============================================================
       PUBLIC USER
    ============================================================ */

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
