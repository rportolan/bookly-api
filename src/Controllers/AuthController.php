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
use App\Repositories\MagicLinkRepository;
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

        $firstName = trim((string)($body['firstName'] ?? ''));
        $lastName = trim((string)($body['lastName'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if ($firstName === '' || $lastName === '' || $email === '' || $username === '' || $password === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'required' => ['firstName', 'lastName', 'email', 'username', 'password'],
            ], 'Missing required fields');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'field' => 'email',
            ], 'Invalid email');
        }

        $firstNameLen = mb_strlen($firstName);
        if ($firstNameLen < 2 || $firstNameLen > 60) {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'field' => 'firstName',
                'min' => 2,
                'max' => 60,
            ], 'Invalid first name');
        }

        $lastNameLen = mb_strlen($lastName);
        if ($lastNameLen < 2 || $lastNameLen > 60) {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'field' => 'lastName',
                'min' => 2,
                'max' => 60,
            ], 'Invalid last name');
        }

        $usernameLen = mb_strlen($username);
        if ($usernameLen < 3 || $usernameLen > 60) {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'field' => 'username',
                'min' => 3,
                'max' => 60,
            ], 'Invalid username');
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
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'username' => $username,
            'password_hash' => $hash,
            'email_verified_at' => null,
        ]);

        $user = $repo->findById((int)$userId);
        if (!$user) {
            throw new HttpException(500, 'SERVER_ERROR', [], 'User not found after register');
        }

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

        $identifier = trim((string)($body['username'] ?? $body['identifier'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if ($identifier === '' || $password === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', [
                'required' => ['username', 'password'],
            ], 'Missing required fields');
        }

        $repo = new UserRepository();

        $user = str_contains($identifier, '@')
            ? $repo->findByEmail($identifier)
            : $repo->findByUsername($identifier);

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
                'email' => (string)($user['email'] ?? ''),
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
       MAGIC LINK
    ============================================================ */

    /**
     * POST /v1/auth/magic-link/request
     * Body: { "email": "..." }
     * Génère un code OTP à 6 chiffres et l'envoie par email.
     */
    public function magicLinkRequest(): void
    {
        $body  = Request::json();
        $email = trim(strtolower((string)($body['email'] ?? '')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::ok(['sent' => true]);
            return;
        }

        $repo = new UserRepository();
        $user = $repo->findByEmail($email);

        if (!$user) {
            $userId = $repo->create([
                'email'             => $email,
                'email_verified_at' => null,
            ]);
            $user = $repo->findById($userId);
        }

        $userId   = (int)$user['id'];
        $mlRepo   = new MagicLinkRepository();

        if (!$mlRepo->canSend($email, 60)) {
            Response::ok(['sent' => true]);
            return;
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = hash('sha256', $code);
        $ttl  = (int)Env::get('MAGIC_LINK_TTL', '900');

        $mlRepo->upsertForUser($userId, $email, $hash, $ttl);
        $this->sendOtpEmail($email, $code);

        Response::ok(['sent' => true]);
    }

    /**
     * POST /v1/auth/magic-link/verify-code
     * Body: { "email": "...", "code": "123456" }
     * Vérifie le code OTP et retourne les tokens en JSON.
     */
    public function magicLinkVerifyCode(): void
    {
        $body  = Request::json();
        $email = trim(strtolower((string)($body['email'] ?? '')));
        $code  = trim((string)($body['code'] ?? ''));

        if ($email === '' || $code === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['required' => ['email', 'code']], 'Missing fields');
        }

        $hash   = hash('sha256', $code);
        $mlRepo = new MagicLinkRepository();
        $row    = $mlRepo->findValidByTokenHash($hash);

        if (!$row || (string)$row['email'] !== $email) {
            throw new HttpException(401, 'INVALID_CODE', [], 'Code invalide ou expiré');
        }

        $userId   = (int)$row['user_id'];
        $userRepo = new UserRepository();
        $user     = $userRepo->findById($userId);

        if (!$user) {
            throw new HttpException(500, 'SERVER_ERROR', [], 'User not found');
        }

        $mlRepo->markUsed((int)$row['id']);
        $userRepo->markEmailVerified($userId);

        [$at, $rt] = $this->issueTokens($userId);

        Response::ok([
            'user'   => $this->publicUser($user),
            'tokens' => [
                'tokenType'    => 'Bearer',
                'accessToken'  => $at,
                'refreshToken' => $rt,
                'expiresIn'    => (int)Env::get('JWT_ACCESS_TTL', '900'),
            ],
            'isNew' => empty($user['onboarding_completed']),
        ]);
    }

    /* ============================================================
       ONBOARDING
    ============================================================ */

    /**
     * POST /v1/auth/onboarding
     * Sauvegarde les données d'onboarding (prénom, objectif, genres).
     * Nécessite un token valide.
     */
    public function saveOnboarding(): void
    {
        $uid  = Auth::requireAuth();
        $body = Request::json();

        $repo = new UserRepository();
        $existing = $repo->findById($uid);

        if (!$existing) {
            throw new HttpException(401, 'UNAUTHORIZED', [], 'Unauthorized');
        }

        // Compte déjà onboardé → on n'écrase JAMAIS le profil existant.
        // (protège contre une ré-inscription avec un email déjà utilisé)
        if (!empty($existing['onboarding_completed'])) {
            Response::ok(['user' => $this->publicUser($existing)]);
            return;
        }

        $firstName    = trim((string)($body['firstName']    ?? ''));
        $readingGoal  = trim((string)($body['readingGoal']  ?? 'regulier'));
        $rawGenres    = is_array($body['preferredGenres'] ?? null) ? $body['preferredGenres'] : [];

        $allowedGoals = ['occasionnel', 'regulier', 'passionne', 'vorace'];
        if (!in_array($readingGoal, $allowedGoals, true)) {
            $readingGoal = 'regulier';
        }

        $genres = array_values(array_filter(
            array_map(fn($g) => trim((string)$g), $rawGenres),
            fn($g) => $g !== ''
        ));

        $repo->saveOnboarding($uid, $firstName, $readingGoal, $genres);

        $user = $repo->findById($uid);
        Response::ok(['user' => $this->publicUser($user)]);
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
            $this->renderSimpleHtml(
                'Lien invalide.',
                false,
                'Readout - Vérification',
                'Vérification email',
                'Lien invalide',
                'Ce lien de confirmation est incomplet ou incorrect.'
            );
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->renderSimpleHtml(
                'Adresse email invalide.',
                false,
                'Readout - Vérification',
                'Vérification email',
                'Adresse invalide',
                'Cette adresse email ne semble pas valide.'
            );
            return;
        }

        $tokenHash = hash('sha256', $token);

        $verifyRepo = new EmailVerificationRepository();
        $row = $verifyRepo->findValidByEmailAndTokenHash($email, $tokenHash);

        if (!$row) {
            $this->renderSimpleHtml(
                'Lien invalide ou expiré.',
                false,
                'Readout - Vérification',
                'Vérification email',
                'Lien indisponible',
                'Ce lien de vérification est invalide ou a expiré.'
            );
            return;
        }

        if (!empty($row['used_at'])) {
            $this->renderSimpleHtml(
                'Ton email est déjà vérifié.',
                true,
                'Readout - Vérification',
                'Vérification email',
                'Email déjà confirmé',
                'Ton adresse email est déjà confirmée. Tu peux retourner dans l’application.'
            );
            return;
        }

        $expiresAt = strtotime((string)$row['expires_at']);
        if ($expiresAt <= 0 || $expiresAt < time()) {
            $this->renderSimpleHtml(
                'Lien expiré.',
                false,
                'Readout - Vérification',
                'Vérification email',
                'Lien expiré',
                'Ce lien de confirmation a expiré. Demande un nouvel email depuis l’application.'
            );
            return;
        }

        $userId = (int)$row['user_id'];

        $userRepo = new UserRepository();
        $userRepo->markEmailVerified($userId);
        $verifyRepo->markUsed($userId);

        $this->renderSimpleHtml(
            'Email vérifié avec succès.',
            true,
            'Readout - Vérification',
            'Vérification email',
            'Email confirmé',
            'Ton compte est maintenant activé. Tu peux retourner dans Readout et te connecter.'
        );
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
            echo $this->resetHtmlPage(
                'Lien invalide.',
                false,
                false,
                $email,
                $token
            );
            return;
        }

        echo $this->resetHtmlPage(
            'Choisis un nouveau mot de passe pour sécuriser ton compte.',
            true,
            true,
            $email,
            $token
        );
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

        $email = trim((string)($_POST['email'] ?? ''));
        $token = trim((string)($_POST['token'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');

        header('Content-Type: text/html; charset=utf-8');

        try {
            $this->resetPasswordCore($email, $token, $newPassword);

            echo $this->resetHtmlPage(
                'Mot de passe mis à jour.',
                true,
                false,
                $email,
                $token
            );
            return;
        } catch (\Throwable $e) {
            $msg = 'Lien invalide ou expiré.';
            if ($e instanceof HttpException) {
                $msg = match ($e->errorCode) {
                    'RESET_EXPIRED' => 'Lien expiré.',
                    'RESET_ALREADY_USED' => 'Lien déjà utilisé.',
                    'VALIDATION_ERROR' => 'Mot de passe invalide (minimum 8 caractères).',
                    default => 'Lien invalide ou expiré.',
                };
            }

            echo $this->resetHtmlPage(
                $msg,
                false,
                true,
                $email,
                $token
            );
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
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        $content = '';

        if ($showForm) {
            $content = "
                <form method='POST' action='/v1/auth/reset-password' class='auth-form'>
                    <input type='hidden' name='email' value='{$safeEmail}' />
                    <input type='hidden' name='token' value='{$safeToken}' />

                    <label class='field-label' for='new_password'>Nouveau mot de passe</label>
                    <input
                        id='new_password'
                        type='password'
                        name='new_password'
                        placeholder='Minimum 8 caractères'
                        class='auth-input'
                        autocomplete='new-password'
                        required
                        minlength='8'
                    />

                    <button type='submit' class='primary-button'>
                        Mettre à jour le mot de passe
                    </button>

                    <p class='meta-text'>Compte concerné : {$safeEmail}</p>
                </form>
            ";
        } else {
            $content = "
                <div class='stack'>
                    <p class='body-muted'>Retourne dans l’application et reconnecte-toi avec ton nouveau mot de passe.</p>
                </div>
            ";
        }

        return $this->renderAuthPage([
            'title' => 'Readout - Réinitialisation du mot de passe',
            'status' => $ok ? 'success' : 'error',
            'eyebrow' => 'Sécurité du compte',
            'heading' => $ok ? 'Mot de passe mis à jour' : 'Réinitialisation du mot de passe',
            'message' => $message,
            'content' => $content,
            'footer' => "Si tu n'es pas à l'origine de cette demande, tu peux ignorer cette page.",
            'showAppHint' => !$showForm,
        ]);
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

        $subject = 'Confirme ton adresse email - Readout';

        $html = $this->buildEmailLayout([
            'eyebrow' => 'Bienvenue sur Readout',
            'title' => 'Confirme ton adresse email',
            'intro' => "Ton compte est presque prêt. Confirme ton adresse email pour activer Readout et commencer à suivre tes lectures.",
            'buttonLabel' => 'Confirmer mon email',
            'buttonUrl' => $verifyUrl,
            'note' => "Ce lien est valable 1 heure. Si tu n'es pas à l'origine de cette inscription, tu peux simplement ignorer cet email.",
        ]);

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

        $subject = 'Réinitialise ton mot de passe - Readout';

        $html = $this->buildEmailLayout([
            'eyebrow' => 'Sécurité du compte',
            'title' => 'Réinitialise ton mot de passe',
            'intro' => "Tu as demandé à réinitialiser le mot de passe de ton compte Readout. Clique sur le bouton ci-dessous pour en choisir un nouveau.",
            'buttonLabel' => 'Réinitialiser mon mot de passe',
            'buttonUrl' => $resetUrl,
            'note' => "Ce lien est valable 1 heure. Si tu n'es pas à l'origine de cette demande, ignore cet email : ton mot de passe ne sera pas modifié.",
        ]);

        Mailer::send($email, $subject, $html);
    }

    private function buildEmailLayout(array $data): string
    {
        $eyebrow = htmlspecialchars((string)($data['eyebrow'] ?? 'Readout'), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars((string)($data['title'] ?? 'Notification'), ENT_QUOTES, 'UTF-8');
        $intro = htmlspecialchars((string)($data['intro'] ?? ''), ENT_QUOTES, 'UTF-8');
        $buttonLabel = htmlspecialchars((string)($data['buttonLabel'] ?? ''), ENT_QUOTES, 'UTF-8');
        $buttonUrl = htmlspecialchars((string)($data['buttonUrl'] ?? ''), ENT_QUOTES, 'UTF-8');
        $note = htmlspecialchars((string)($data['note'] ?? ''), ENT_QUOTES, 'UTF-8');
        $extra = (string)($data['extra'] ?? '');

        $font = "'Jost',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
        $logoUrl = rtrim((string)Env::get('APP_URL', 'http://localhost:8080'), '/') . '/readout-logo.png';

        $button = $buttonLabel !== '' ? "
                    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:0 0 24px;\">
                        <tr>
                            <td style=\"border-radius:14px;background:#685BFF;\">
                                <a href=\"{$buttonUrl}\"
                                   style=\"display:inline-block;padding:15px 28px;font-family:{$font};font-size:15px;font-weight:800;color:#ffffff;text-decoration:none;border-radius:14px;\">
                                    {$buttonLabel}
                                </a>
                            </td>
                        </tr>
                    </table>

                    <div style=\"padding:14px 16px;border-radius:12px;background:#1f211f;border:1px solid #2c2e2c;\">
                        <p style=\"margin:0 0 6px;font-family:{$font};font-size:11px;font-weight:700;color:#7f817e;text-transform:uppercase;letter-spacing:.08em;\">
                            Lien direct
                        </p>
                        <p style=\"margin:0;font-family:{$font};font-size:13px;line-height:1.7;word-break:break-all;color:#a8aaa6;\">
                            {$buttonUrl}
                        </p>
                    </div>" : "";

        return "
        <div style=\"margin:0;padding:36px 16px;background:#101211;\">
            <div style=\"max-width:520px;margin:0 auto;font-family:{$font};color:#f4f4f3;\">

                <div style=\"text-align:center;margin-bottom:26px;\">
                    <img src=\"{$logoUrl}\" alt=\"Readout\" width=\"42\" height=\"42\" style=\"display:inline-block;vertical-align:middle;border-radius:11px;\" />
                    <span style=\"display:inline-block;vertical-align:middle;margin-left:12px;font-size:22px;font-weight:900;letter-spacing:-.4px;color:#f4f4f3;\">Readout</span>
                </div>

                <div style=\"background:#1a1c1b;border:1px solid #2a2c2b;border-radius:22px;padding:36px 30px;\">
                    <div style=\"display:inline-block;padding:6px 13px;border-radius:999px;background:#201f3a;color:#9b8cff;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;margin-bottom:18px;\">
                        {$eyebrow}
                    </div>

                    <h1 style=\"margin:0 0 12px;font-size:26px;line-height:1.18;letter-spacing:-.6px;color:#f4f4f3;font-weight:900;\">
                        {$title}
                    </h1>

                    <p style=\"margin:0 0 26px;font-size:15px;line-height:1.7;color:#a8aaa6;\">
                        {$intro}
                    </p>

                    {$extra}

                    {$button}

                    <hr style=\"border:none;border-top:1px solid #2a2c2b;margin:26px 0;\">

                    <p style=\"margin:0;font-size:13px;line-height:1.7;color:#7f817e;\">
                        {$note}
                    </p>
                </div>

                <p style=\"margin:18px 0 0;text-align:center;font-size:12px;line-height:1.6;color:#5e605d;\">
                    Email automatique envoyé par Readout. Pense à vérifier tes spams si besoin.
                </p>
            </div>
        </div>
        ";
    }

    /* ============================================================
       HELPERS: HTML RENDER
    ============================================================ */

    private function renderSimpleHtml(
        string $message,
        bool $success,
        string $title,
        string $eyebrow = 'Readout',
        string $heading = '',
        string $description = ''
    ): void {
        header('Content-Type: text/html; charset=utf-8');

        echo $this->renderAuthPage([
            'title' => $title,
            'status' => $success ? 'success' : 'error',
            'eyebrow' => $eyebrow,
            'heading' => $heading !== '' ? $heading : ($success ? 'C’est bon' : 'Oups'),
            'message' => $description !== '' ? $description : $message,
            'content' => '',
            'footer' => 'Tu peux maintenant retourner dans l’application et te connecter.',
            'showAppHint' => true,
        ]);
    }

    private function renderAuthPage(array $data): string
    {
        $title = htmlspecialchars((string)($data['title'] ?? 'Readout'), ENT_QUOTES, 'UTF-8');
        $status = (string)($data['status'] ?? 'neutral');
        $eyebrow = htmlspecialchars((string)($data['eyebrow'] ?? 'Readout'), ENT_QUOTES, 'UTF-8');
        $heading = htmlspecialchars((string)($data['heading'] ?? 'Information'), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars((string)($data['message'] ?? ''), ENT_QUOTES, 'UTF-8');
        $footer = htmlspecialchars((string)($data['footer'] ?? ''), ENT_QUOTES, 'UTF-8');
        $content = (string)($data['content'] ?? '');
        $showAppHint = (bool)($data['showAppHint'] ?? false);

        $accent = match ($status) {
            'success' => '#22c55e',
            'error' => '#ef4444',
            default => '#7c3aed',
        };

        $icon = match ($status) {
            'success' => '✓',
            'error' => '!',
            default => '•',
        };

        $appHint = $showAppHint
            ? "<p class='bottom-note'>Si l’application ne s’ouvre pas automatiquement, retourne simplement sur Readout.</p>"
            : '';

        return "
        <!DOCTYPE html>
        <html lang='fr'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>{$title}</title>
            <style>
                :root {
                    --bg: #0b1020;
                    --bg-soft: #11172a;
                    --card: rgba(17, 24, 39, 0.72);
                    --card-border: rgba(255, 255, 255, 0.08);
                    --text: #f8fafc;
                    --muted: #94a3b8;
                    --muted-2: #cbd5e1;
                    --input-bg: rgba(255, 255, 255, 0.04);
                    --input-border: rgba(255, 255, 255, 0.10);
                    --shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
                    --radius-xl: 28px;
                    --radius-lg: 18px;
                    --radius-md: 14px;
                }

                * {
                    box-sizing: border-box;
                }

                html, body {
                    margin: 0;
                    padding: 0;
                    min-height: 100%;
                    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                    background:
                        radial-gradient(circle at top, rgba(124, 58, 237, 0.22), transparent 32%),
                        radial-gradient(circle at bottom right, rgba(59, 130, 246, 0.14), transparent 28%),
                        linear-gradient(180deg, #0a0f1d 0%, #0f172a 100%);
                    color: var(--text);
                }

                body {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 24px;
                }

                .shell {
                    width: 100%;
                    max-width: 520px;
                }

                .card {
                    position: relative;
                    overflow: hidden;
                    background: var(--card);
                    border: 1px solid var(--card-border);
                    border-radius: var(--radius-xl);
                    padding: 32px;
                    backdrop-filter: blur(18px);
                    box-shadow: var(--shadow);
                }

                .glow {
                    position: absolute;
                    inset: auto -80px -80px auto;
                    width: 180px;
                    height: 180px;
                    background: {$accent};
                    opacity: 0.12;
                    filter: blur(50px);
                    border-radius: 999px;
                    pointer-events: none;
                }

                .status-badge {
                    width: 52px;
                    height: 52px;
                    border-radius: 999px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 22px;
                    font-weight: 800;
                    background: rgba(255, 255, 255, 0.06);
                    border: 1px solid rgba(255, 255, 255, 0.10);
                    color: {$accent};
                    margin-bottom: 18px;
                }

                .eyebrow {
                    margin: 0 0 8px;
                    font-size: 12px;
                    font-weight: 700;
                    letter-spacing: 0.12em;
                    text-transform: uppercase;
                    color: var(--muted);
                }

                h1 {
                    margin: 0;
                    font-size: 32px;
                    line-height: 1.1;
                    letter-spacing: -0.03em;
                }

                .message {
                    margin: 14px 0 0;
                    color: var(--muted-2);
                    font-size: 15px;
                    line-height: 1.65;
                }

                .section {
                    margin-top: 24px;
                }

                .stack {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                }

                .auth-form {
                    display: flex;
                    flex-direction: column;
                    gap: 14px;
                    margin-top: 8px;
                }

                .field-label {
                    font-size: 13px;
                    font-weight: 600;
                    color: #e2e8f0;
                }

                .auth-input {
                    width: 100%;
                    border: 1px solid var(--input-border);
                    background: var(--input-bg);
                    color: var(--text);
                    border-radius: var(--radius-md);
                    padding: 14px 16px;
                    font-size: 15px;
                    outline: none;
                    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
                }

                .auth-input::placeholder {
                    color: #64748b;
                }

                .auth-input:focus {
                    border-color: rgba(124, 58, 237, 0.75);
                    box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.18);
                    background: rgba(255, 255, 255, 0.06);
                }

                .primary-button {
                    width: 100%;
                    border: 0;
                    border-radius: var(--radius-md);
                    padding: 15px 18px;
                    background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%);
                    color: white;
                    font-size: 15px;
                    font-weight: 800;
                    cursor: pointer;
                    transition: transform 0.15s ease, opacity 0.15s ease, filter 0.15s ease;
                    box-shadow: 0 16px 32px rgba(124, 58, 237, 0.24);
                }

                .primary-button:hover {
                    filter: brightness(1.03);
                }

                .primary-button:active {
                    transform: translateY(1px);
                }

                .meta-text {
                    margin: 2px 0 0;
                    font-size: 12px;
                    line-height: 1.5;
                    color: var(--muted);
                }

                .body-muted {
                    margin: 0;
                    color: var(--muted-2);
                    font-size: 14px;
                    line-height: 1.6;
                }

                .footer {
                    margin-top: 24px;
                    padding-top: 18px;
                    border-top: 1px solid rgba(255, 255, 255, 0.08);
                    color: var(--muted);
                    font-size: 12px;
                    line-height: 1.6;
                }

                .bottom-note {
                    margin: 18px 0 0;
                    text-align: center;
                    color: #64748b;
                    font-size: 12px;
                    line-height: 1.6;
                }

                @media (max-width: 560px) {
                    .card {
                        padding: 24px;
                        border-radius: 22px;
                    }

                    h1 {
                        font-size: 28px;
                    }
                }
            </style>
        </head>
        <body>
            <main class='shell'>
                <section class='card'>
                    <div class='glow'></div>

                    <div class='status-badge'>{$icon}</div>

                    <p class='eyebrow'>{$eyebrow}</p>
                    <h1>{$heading}</h1>
                    <p class='message'>{$message}</p>

                    " . ($content !== '' ? "<div class='section'>{$content}</div>" : "") . "

                    <div class='footer'>{$footer}</div>
                </section>

                {$appHint}
            </main>
        </body>
        </html>
        ";
    }

    /* ============================================================
       HELPERS: MAGIC LINK EMAIL
    ============================================================ */

    private function sendOtpEmail(string $email, string $code): void
    {
        $digits = str_split($code);
        $font = "'Jost',-apple-system,'Segoe UI',Helvetica,Arial,sans-serif";

        // Table = une seule ligne garantie (les <td> ne wrappent jamais),
        // cases compactes pour tenir sur les écrans mobiles étroits.
        $cells = '';
        foreach ($digits as $d) {
            $cells .= "<td style=\"width:40px;height:52px;text-align:center;vertical-align:middle;"
                . "font-family:{$font};font-size:23px;font-weight:800;color:#ffffff;"
                . "background:#222423;border:1px solid #34363a;border-radius:12px;\">{$d}</td>";
        }
        $digitBoxes = "<table role=\"presentation\" align=\"center\" cellpadding=\"0\" cellspacing=\"6\" "
            . "style=\"margin:28px auto;border-collapse:separate;\"><tr>{$cells}</tr></table>";

        $html = $this->buildEmailLayout([
            'eyebrow'     => 'Connexion à Readout',
            'title'       => 'Ton code de connexion',
            'intro'       => "Utilise ce code dans l'application pour te connecter. Il est valable 15 minutes.",
            'buttonLabel' => '',
            'buttonUrl'   => '',
            'note'        => "Si tu n'es pas à l'origine de cette demande, ignore cet email. Ne partage jamais ce code.",
            'extra'       => $digitBoxes,
        ]);

        Mailer::send($email, 'Ton code de connexion Readout', $html);
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
            'iss' => 'readout-api',
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
        if (!$u) {
            return [];
        }

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

        $rawGenres = (string)($u['preferred_genres'] ?? '');
        $genres    = $rawGenres !== '' ? (json_decode($rawGenres, true) ?? []) : [];

        return [
            'id'        => (int)($u['id'] ?? 0),
            'email'     => (string)($u['email'] ?? ''),
            'username'  => $u['username'] !== null ? (string)$u['username'] : null,
            'firstName' => (string)($u['first_name'] ?? ''),
            'lastName'  => (string)($u['last_name'] ?? ''),
            'bio'       => $u['bio'] ?? null,
            'avatarUrl' => $u['avatar_url'] ?? null,

            'emailVerified'   => $isVerified,
            'emailVerifiedAt' => $u['email_verified_at'] ?? null,

            'onboardingCompleted' => !empty($u['onboarding_completed']),
            'readingGoal'         => $u['reading_goal'] ?? null,
            'preferredGenres'     => is_array($genres) ? $genres : [],

            'progress' => $progress,

            'xp'     => (int)($progress['xp'] ?? 0),
            'level'  => (int)($progress['level'] ?? 1),
            'title'  => (string)($progress['title'] ?? 'Lecteur novice'),
            'badges' => is_array($progress['badges'] ?? null) ? $progress['badges'] : [],

            'preferences' => [
                'goalPagesPerDay' => $goal,
                'language'        => $lang,
                'density'         => $density,
            ],
            'createdAt' => $u['created_at'] ?? null,
            'updatedAt' => $u['updated_at'] ?? null,
        ];
    }
}