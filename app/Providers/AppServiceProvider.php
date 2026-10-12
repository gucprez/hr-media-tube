<?php

namespace App\Providers;

use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);
    }

    public function boot(): void
    {
        Paginator::defaultView('pagination.tailwind-es');

        // Todo `@can('products.edit')` / Gate::allows() se resuelve por permiso individual.
        Gate::before(function ($user, string $ability) {
            if (str_contains($ability, '.')) {
                return $user->hasPermission($ability);
            }

            return null;
        });

        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $menu = collect(config('menu'))->map(function ($section) use ($user) {
                $items = collect($section['items'])->filter(function ($item) use ($user) {
                    if (! Route::has($item['route'])) {
                        return false;
                    }
                    if (! empty($item['super'])) {
                        return $user?->is_super_admin;
                    }
                    if (! empty($item['can'])) {
                        return $user?->hasPermission($item['can']);
                    }

                    return true;
                })->map(fn ($i) => $i + ['active' => request()->routeIs($i['route'], \Illuminate\Support\Str::beforeLast($i['route'], '.').'.*')]);

                return ['label' => $section['label'], 'items' => $items];
            })->filter(fn ($s) => $s['items']->isNotEmpty());
            $view->with('menu', $menu);
        });

        RateLimiter::for('login', function (Request $request) {
            $key = mb_strtolower((string) $request->input('username')).'|'.$request->ip();

            return [Limit::perMinute(5)->by($key), Limit::perMinute(30)->by($request->ip())];
        });
    }
}
