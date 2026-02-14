<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Env;
use App\Core\Router;
use App\Core\Response;
use App\Core\HttpException;

use App\Controllers\HealthController;
use App\Controllers\AuthController;
use App\Controllers\UsersController;
use App\Controllers\BooksController;
use App\Controllers\QuotesController;
use App\Controllers\VocabController;
use App\Controllers\ChaptersController;
use App\Controllers\QuestsController;
use App\Controllers\ReadingController;
use App\Controllers\LearnController;
use App\Controllers\DashboardController;

/**
 * Bootstrap env
 */
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    Env::load($envFile);
}

/**
 * JSON by default (API)
 */
header('Content-Type: application/json; charset=utf-8');

/**
 * CORS
 */
$allowed = Env::get('CORS_ORIGIN', 'http://localhost:5173');
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowed))));
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($reqOrigin && in_array($reqOrigin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$reqOrigin}");
    header("Vary: Origin");
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS");

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Optional sessions (OFF by default)
 */
try {
    $enableSessions = Env::get('ENABLE_SESSIONS', '0') === '1';
    if ($enableSessions) {
        $cookieParams = session_get_cookie_params();
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => $cookieParams['domain'] ?? '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('bookly_session');
        session_start();
    }
} catch (Throwable $e) {
    error_log("[BOOKLY][sessions] " . $e->getMessage());
}

/**
 * Central error handler
 */
set_exception_handler(function (Throwable $e) {
    $env = Env::get('APP_ENV', 'prod');
    $isDev = ($env === 'dev');

    error_log("[BOOKLY][exception] " . get_class($e) . " :: " . $e->getMessage());
    error_log($e->getTraceAsString());

    if ($e instanceof HttpException) {
        Response::error(
            $e->errorCode,
            $e->getMessage(),
            $e->status,
            $isDev ? $e->details : []
        );
        exit;
    }

    $details = $isDev ? [
        'type' => get_class($e),
        'message' => $e->getMessage(),
    ] : [];

    Response::error('SERVER_ERROR', 'Server error', 500, $details);
    exit;
});

/**
 * Router
 */
$router = new Router();

/**
 * Root route
 */
$router->add('GET', '/', function () {
    echo json_encode([
        'ok' => true,
        'service' => 'bookly-api',
        'version' => 'v1'
    ]);
});

/**
 * Controllers
 */
$health     = new HealthController();
$auth       = new AuthController();
$dashboard  = new DashboardController();
$users      = new UsersController();
$books      = new BooksController();
$quotes     = new QuotesController();
$vocab      = new VocabController();
$chapters   = new ChaptersController();
$quests     = new QuestsController();
$reading    = new ReadingController();
$learn      = new LearnController();

/**
 * API prefix
 */
$prefix = '/v1';

// Health
$router->add('GET', "{$prefix}/health", $health);

// Auth
$router->add('POST', "{$prefix}/auth/register", fn() => $auth->register());
$router->add('POST', "{$prefix}/auth/login",    fn() => $auth->login());
$router->add('POST', "{$prefix}/auth/logout",   fn() => $auth->logout());
$router->add('GET',  "{$prefix}/me",            fn() => $auth->me());
$router->add('POST', "{$prefix}/auth/refresh",  fn() => $auth->refresh());
$router->add('POST', "{$prefix}/auth/logout-all", fn() => $auth->logoutAll());

// ✅ Email verification (new)
$router->add('GET',  "{$prefix}/auth/verify-email", fn() => $auth->verifyEmail());
$router->add('POST', "{$prefix}/auth/resend-verification", fn() => $auth->resendVerification());

// Password reset (new)
$router->add('POST', "{$prefix}/auth/forgot-password", fn() => $auth->forgotPassword());
$router->add('POST', "{$prefix}/auth/reset-password", fn() => $auth->resetPassword());

// Fallback HTML (new) : lien cliquable depuis email
$router->add('GET',  "{$prefix}/auth/reset-password", fn() => $auth->resetPasswordHtml());


// Dashboard
$router->add('GET', "{$prefix}/dashboard/continue", fn() => $dashboard->continue());

// Users
$router->add('PATCH',  "{$prefix}/me",          fn() => $users->updateMe());
$router->add('PATCH',  "{$prefix}/me/password", fn() => $users->changePassword());
$router->add('DELETE', "{$prefix}/me",          fn() => $users->deleteMe());

// Books
$router->add('GET',    "{$prefix}/books",               fn() => $books->index());
$router->add('POST',   "{$prefix}/books",               fn() => $books->store());
$router->add('GET',    "{$prefix}/books/{id:\d+}",      fn($p) => $books->show($p));
$router->add('PATCH',  "{$prefix}/books/{id:\d+}",      fn($p) => $books->update($p));
$router->add('DELETE', "{$prefix}/books/{id:\d+}",      fn($p) => $books->destroy($p));
$router->add('PATCH',  "{$prefix}/books/{id:\d+}/progress", fn($p) => $books->updateProgress($p));

// Quotes
$router->add('GET',    "{$prefix}/books/{id}/quotes",            fn($p) => $quotes->index($p));
$router->add('POST',   "{$prefix}/books/{id}/quotes",            fn($p) => $quotes->store($p));
$router->add('DELETE', "{$prefix}/books/{id}/quotes/{quoteId}",  fn($p) => $quotes->destroy($p));
$router->add('PATCH',  "{$prefix}/books/{id}/quotes/{quoteId}",  fn($p) => $quotes->update($p));

// Vocab
$router->add('GET',    "{$prefix}/books/{id}/vocab",            fn($p) => $vocab->index($p));
$router->add('POST',   "{$prefix}/books/{id}/vocab",            fn($p) => $vocab->store($p));
$router->add('DELETE', "{$prefix}/books/{id}/vocab/{vocabId}",   fn($p) => $vocab->destroy($p));
$router->add('PATCH',  "{$prefix}/books/{id}/vocab/{vocabId}",   fn($p) => $vocab->update($p));

// Chapters
$router->add('GET',    "{$prefix}/books/{id}/chapters",              fn($p) => $chapters->index($p));
$router->add('POST',   "{$prefix}/books/{id}/chapters",              fn($p) => $chapters->store($p));
$router->add('PATCH',  "{$prefix}/books/{id}/chapters/{chapterId}",   fn($p) => $chapters->update($p));
$router->add('DELETE', "{$prefix}/books/{id}/chapters/{chapterId}",   fn($p) => $chapters->destroy($p));

// Quests
$router->add('GET', "{$prefix}/quests/summary", fn() => $quests->summary());

// Reading
$router->add('GET',   "{$prefix}/reading/goal",        fn() => $reading->getGoal());
$router->add('PATCH', "{$prefix}/reading/goal",        fn() => $reading->updateGoal());
$router->add('GET',   "{$prefix}/reading/log",         fn() => $reading->getLog());
$router->add('PATCH', "{$prefix}/reading/log/today",   fn() => $reading->upsertToday());

// Learn
$router->add('GET',  "{$prefix}/learn/books",            fn() => $learn->books());
$router->add('GET',  "{$prefix}/learn/deck",             fn() => $learn->deck());
$router->add('POST', "{$prefix}/learn/session/complete", fn() => $learn->completeSession());

/**
 * Dispatch
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if ($uri !== '/' && str_ends_with($uri, '/')) {
    $uri = rtrim($uri, '/');
}

/**
 * Alias: /api/* => /v1/*
 */
if (str_starts_with($uri, '/api/')) {
    $uri = '/v1' . substr($uri, 4);
}

$router->dispatch($method, $uri);
