<?php

namespace App\Providers;

use App\Support\CurrentCompany;
use App\Support\Search\CategorySearch;
use App\Support\Search\ContainerSearch;
use App\Support\Search\TicketSearch;
use App\Support\Search\VehicleSearch;
use App\Support\Search\GlobalSearch;
use App\Support\Search\ProductSearch;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);

        // Búsqueda global: cada módulo registra su proveedor (Fase 3+: furgones, vehículos, tickets).
        $this->app->singleton(GlobalSearch::class, fn () => tap(new GlobalSearch, function (GlobalSearch $search) {
            $search->register(new ProductSearch);
            $search->register(new CategorySearch);
            $search->register(new ContainerSearch);
            $search->register(new TicketSearch);
            $search->register(new VehicleSearch);
        }));
    }

    public function boot(): void
    {
        Paginator::defaultView('pagination.tailwind-es');

        // N+1: en desarrollo y pruebas una relación cargada perezosamente dentro de un bucle falla ruidosamente; en producción solo se registra.
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function ($model, string $relation) {
            if (app()->isProduction()) {
                \Illuminate\Support\Facades\Log::warning(sprintf('N+1: %s::%s', $model::class, $relation));

                return;
            }
            throw new \Illuminate\Database\LazyLoadingViolationException($model, $relation);
        });

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
                        return collect((array) $item['can'])->contains(fn ($p) => $user?->hasPermission($p));
                    }

                    return true;
                })->map(function ($i) {
                    $params = $i['params'] ?? [];
                    // Ítem con parámetros (secciones de reportes): activo solo si coincide la sección.
                    $active = isset($i['match'])
                        ? request()->routeIs(...$i['match'])
                        : ($params
                        ? request()->routeIs($i['route']) && collect($params)->every(fn ($v, $k) => request()->route($k) === $v)
                        : request()->routeIs($i['route'], \Illuminate\Support\Str::beforeLast($i['route'], '.').'.*'));

                    return $i + ['active' => $active, 'url' => route($i['route'], $params)];
                });

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
