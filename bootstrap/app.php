<?php

use App\Http\Middleware\EnsureCompanySelected;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveCompany;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [ResolveCompany::class, SecurityHeaders::class]);
        // La empresa activa debe fijarse ANTES del route-model-binding para que el
        // aislamiento multiempresa aplique también a {user}, {product}, etc.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveCompany::class);
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'company' => EnsureCompanySelected::class,
            'superadmin' => EnsureSuperAdmin::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
