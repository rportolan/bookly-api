<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\UserRepository;
use App\Repositories\RefreshTokenRepository;
use App\Services\ProgressService;

final class UsersController
{
    public function me(): void
    {
        $uid = Auth::requireAuth();

        $repo = new UserRepository();
        $user = $repo->findById($uid);
        if (!$user) {
            throw new HttpException(401, 'UNAUTHORIZED', [], 'Unauthorized');
        }

        Response::ok($this->publicUser($user));
    }

    public function updateMe(): void
    {
        $uid = Auth::requireAuth();
        $body = Request::json();

        // Champs profil
        $firstName = array_key_exists('firstName', $body) ? trim((string)$body['firstName']) : null;
        $lastName  = array_key_exists('lastName',  $body) ? trim((string)$body['lastName'])  : null;
        $username  = array_key_exists('username',  $body) ? trim((string)$body['username'])  : null;
        $bio       = array_key_exists('bio',       $body) ? trim((string)$body['bio'])       : null;

        // Prefs imbriqués
        $prefs = is_array($body['preferences'] ?? null) ? $body['preferences'] : [];

        $goalPagesPerDay = array_key_exists('goalPagesPerDay', $prefs) ? (int)$prefs['goalPagesPerDay'] : null;
        $language        = array_key_exists('language', $prefs) ? (string)$prefs['language'] : null;
        $density         = array_key_exists('density', $prefs) ? (string)$prefs['density'] : null;

        // Validation MVP
        if ($username !== null) {
            if ($username === '') {
                throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'username'], 'Username requis');
            }
            $len = mb_strlen($username);
            if ($len < 3) {
                throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'username', 'min' => 3], 'Username trop court (min 3)');
            }
            if ($len > 60) {
                throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'username', 'max' => 60], 'Username trop long (max 60)');
            }
        }

        if ($goalPagesPerDay !== null) {
            if ($goalPagesPerDay < 1 || $goalPagesPerDay > 2000) {
                throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'goalPagesPerDay', 'min' => 1, 'max' => 2000], 'Goal invalide');
            }
        }

        if ($language !== null && !in_array($language, ['FR', 'EN'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'language', 'allowed' => ['FR', 'EN']], 'Langue invalide');
        }

        if ($density !== null && !in_array($density, ['Comfort', 'Compact'], true)) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'density', 'allowed' => ['Comfort', 'Compact']], 'Density invalide');
        }

        $repo = new UserRepository();

        // Username unique (check applicatif)
        if ($username !== null) {
            $existing = $repo->findByUsername($username);
            if ($existing && (int)$existing['id'] !== (int)$uid) {
                throw new HttpException(409, 'CONFLICT', ['field' => 'username'], 'Username déjà utilisé');
            }
        }

        // Update
        try {
            $repo->updateById($uid, [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'username'   => $username,
                'bio'        => $bio,
                'goal_pages_per_day' => $goalPagesPerDay,
                'language'   => $language,
                'density'    => $density,
            ]);
        } catch (\PDOException $e) {
            // garde-fou UNIQUE uq_users_username / uq_users_email
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'CONFLICT', ['reason' => 'duplicate'], 'Conflit de données (déjà utilisé)');
            }
            throw $e;
        }

        $user = $repo->findById($uid);
        Response::ok($this->publicUser($user));
    }

    public function changePassword(): void
    {
        $uid = Auth::requireAuth();
        $body = Request::json();

        $current = (string)($body['currentPassword'] ?? '');
        $new     = (string)($body['newPassword'] ?? '');

        if ($current === '' || $new === '') {
            throw new HttpException(422, 'VALIDATION_ERROR', ['fields' => ['currentPassword', 'newPassword']], 'Champs requis: currentPassword, newPassword');
        }

        if (strlen($new) < 8) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'newPassword', 'min' => 8], 'Mot de passe trop court (min 8)');
        }

        if ($current === $new) {
            throw new HttpException(422, 'VALIDATION_ERROR', ['field' => 'newPassword'], 'Le nouveau mot de passe doit être différent');
        }

        $repo = new UserRepository();
        $user = $repo->findById($uid);
        if (!$user) {
            throw new HttpException(401, 'UNAUTHORIZED', [], 'Unauthorized');
        }

        if (!password_verify($current, (string)$user['password_hash'])) {
            throw new HttpException(401, 'UNAUTHORIZED', ['field' => 'currentPassword'], 'Mot de passe actuel incorrect');
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $repo->updatePasswordHash($uid, $hash);

        // Recommandé: révoquer tous les refresh tokens (force logout sur tous devices)
        try {
            $rt = new RefreshTokenRepository();
            $rt->revokeAllForUser($uid);
        } catch (\Throwable $e) {
            // MVP: on log seulement
            error_log('[BOOKLY] revokeAllForUser failed: ' . $e->getMessage());
        }

        Response::ok(['changed' => true]);
    }

    public function deleteMe(): void
    {
        $uid = Auth::requireAuth();

        // recommandé: révoquer refresh tokens avant suppression
        try {
            $rt = new RefreshTokenRepository();
            $rt->revokeAllForUser($uid);
        } catch (\Throwable $e) {
            error_log('[BOOKLY] revokeAllForUser failed: ' . $e->getMessage());
        }

        $repo = new UserRepository();
        $repo->deleteById($uid);

        Response::ok(['deleted' => true]);
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
            // noop MVP
        }

        return [
            'id' => (int)($u['id'] ?? 0),
            'email' => (string)($u['email'] ?? ''),
            'username' => (string)($u['username'] ?? ''),
            'firstName' => (string)($u['first_name'] ?? ''),
            'lastName' => (string)($u['last_name'] ?? ''),
            'bio' => $u['bio'] ?? null,

            'progress' => $progress,
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
