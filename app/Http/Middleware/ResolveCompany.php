<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la empresa activa y bloquea cuentas desactivadas.
 * - Usuario normal: su propia empresa (no se puede cambiar).
 * - Super administrador: la empresa elegida en sesión (si hay).
 */
class ResolveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $ctx = app(CurrentCompany::class);
        $user = Auth::user();

        if ($user) {
            if (! $user->is_active || (! $user->is_super_admin && (! $user->company || ! $user->company->is_active))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['username' => 'Su cuenta o empresa está desactivada.']);
            }

            $ctx->enforce();
            if ($user->is_super_admin) {
                $id = $request->session()->get('active_company_id');
                $company = $id ? Company::find($id) : null;
                $ctx->set($company);
            } else {
                $ctx->set($user->company);
            }
            view()->share('currentCompany', $ctx->get());
        }

        return $next($request);
    }
}
