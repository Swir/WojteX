<?php
/**
 * Database-free check of the real Illuminate AuthenticateSession middleware.
 * Run: php tests/Smoke/SessionRevocationSmoke.php
 *
 * This checks framework hash comparison and the WojteX web-group wiring.
 * It does NOT replace full HTTP/SQLite PHPUnit tests.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;

class SessionSmokeAuth implements AuthFactory
{
    public bool $loggedOut = false;
    public ?GenericUser $currentUser = null;
    public function guard($name = null): static { return $this; }
    public function shouldUse($name): void {}
    public function getDefaultDriver(): string { return 'web'; }
    public function viaRemember(): bool { return false; }
    public function hashPasswordForCookie(?string $hash): string
    {
        return hash_hmac('sha256', $hash ?? '', 'isolated-test-key');
    }
    public function logoutCurrentDevice(): void
    {
        $this->loggedOut = true;
        $this->currentUser = null;
    }
    public function user(): ?GenericUser { return $this->currentUser; }
}

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: '.$message.PHP_EOL);
        exit(1);
    }
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$webGroup = $kernel->getMiddlewareGroups()['web'] ?? [];
ensure(in_array(AuthenticateSession::class, $webGroup, true), 'web middleware lacks AuthenticateSession');

$auth = new SessionSmokeAuth();
$session = new Store('smoke', new ArraySessionHandler(60));
$session->start();
$auth->currentUser = new GenericUser(['id' => 11, 'password' => 'hash-before-reset']);
$request = Request::create('/api/bootstrap', 'GET');
$request->setLaravelSession($session);
$request->setUserResolver(fn () => $auth->currentUser);
$middleware = new AuthenticateSession($auth);

$next = static fn () => new Response('reachable', 200);
ensure($middleware->handle($request, $next)->getContent() === 'reachable', 'initial session request failed');
ensure($session->get('password_hash_web') === $auth->hashPasswordForCookie('hash-before-reset'), 'session hash not recorded');
ensure($middleware->handle($request, $next)->getContent() === 'reachable', 'unchanged password invalidated valid session');
ensure(!$auth->loggedOut, 'valid session was logged out');

// A password reset updates the authoritative password hash in the user provider.
// The next request sees the new user record but the OLD hash in the session.
$auth->currentUser = new GenericUser(['id' => 11, 'password' => 'hash-after-reset']);
$rejected = false;
try {
    $middleware->handle($request, $next);
} catch (AuthenticationException) {
    $rejected = true;
}
ensure($rejected, 'stale session not rejected after password reset');
ensure($auth->loggedOut, 'stale session guard not logged out');
ensure($session->all() === [], 'stale session state was not cleared');

echo 'PASS: web middleware wired, valid session preserved, changed hash revokes session, session flushed'.PHP_EOL;
