<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Services\LanguageRegistry;
use App\Http\Middleware\SetLocale;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['installed'=>\App\Http\Middleware\EnsureInstalled::class,'admin'=>\App\Http\Middleware\RequireAdmin::class,'active_pm'=>\App\Http\Middleware\RequireActivePrivateMessaging::class,'fresh_forum_reader'=>\App\Http\Middleware\RequireFreshForumViewer::class]);
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Laravel's SessionGuard stores a password-hash HMAC at login. Validate it
        // on every subsequent web/API request so a password reset revokes old
        // sessions (including ones established before this middleware shipped).
        $middleware->web(append: [SetLocale::class, AuthenticateSession::class]);
        // Session is available, but language must already be set for CSRF failures.
        $middleware->appendToPriorityList(StartSession::class, SetLocale::class);
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['install_token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $ensureLocale = static function (): void {
            if (!app('translator')->has('server.server_error')) {
                $languages = app(LanguageRegistry::class);
                $languages->activate($languages->defaultLocale());
            }
        };
        $json = static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();
        $exceptions->render(function (ValidationException $exception, Request $request) use ($json, $ensureLocale) {
            if (!$json($request)) return null;
            $ensureLocale();
            return response()->json(['message'=>__('server.validation_failed'),'errors'=>$exception->errors()], $exception->status);
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($json, $ensureLocale) {
            if (!$json($request)) return null;
            $ensureLocale();
            return response()->json(['message'=>__('server.unauthenticated')], 401);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($json, $ensureLocale) {
            $ensureLocale();
            $status = $exception->getStatusCode();
            $key = match ($status) {
                401=>'unauthenticated', 403=>'forbidden', 404=>'not_found',
                405=>'method_not_allowed', 419=>'csrf_expired', 422=>'validation_failed',
                429=>'too_many_requests', 503=>'unavailable', default=>$status >= 500 ? 'server_error' : 'http_error',
            };
            $message = $exception->getMessage();
            if ($message === '' || in_array($status, [405,419,429], true) || $status >= 500
                || $exception->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || str_starts_with($message, 'The route ')) $message = __('server.'.$key);
            return $json($request)
                ? response()->json(['message'=>$message], $status, $exception->getHeaders())
                : response()->view('errors.status', ['status'=>$status,'message'=>$message], $status, $exception->getHeaders());
        });
        $exceptions->render(function (\Throwable $exception, Request $request) use ($json, $ensureLocale) {
            if (config('app.debug') || $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof \Illuminate\Http\Exceptions\HttpResponseException) return null;
            $ensureLocale();
            $message = __('server.server_error');
            return $json($request) ? response()->json(['message'=>$message], 500)
                : response()->view('errors.status', ['status'=>500,'message'=>$message], 500);
        });
    })->create();
