<?php
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => EnsureActiveUser::class, 'source-session' => \App\Http\Middleware\EnsureWebSession::class]);
        // The signed upload URL replaces a presigned PUT and is called without a session token.
        $middleware->validateCsrfTokens(except: ['api/backend/identity-documents/upload', 'api/backend/support/helpdesk/upload']);
        $middleware->web(append: [\App\Http\Middleware\NormalizeApiJson::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request, $exception) => $request->is('api/*') || $request->expectsJson());
    })
    ->create();
