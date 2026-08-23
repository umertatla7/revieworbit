<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateIntegrationKey;
use App\Http\Middleware\RequireBusinessRole;
use App\Http\Middleware\RequirePlatformRole;
use App\Http\Middleware\ResolveBusiness;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : rtrim(config('services.frontend.url'), '/').'/login');
        $middleware->statefulApi();
        $middleware->alias([
            'business' => ResolveBusiness::class,
            'business.role' => RequireBusinessRole::class,
            'platform.role' => RequirePlatformRole::class,
            'integration.key' => AuthenticateIntegrationKey::class,
        ]);
        $middleware->append(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
