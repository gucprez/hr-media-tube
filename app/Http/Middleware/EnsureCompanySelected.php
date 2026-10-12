<?php

namespace App\Http\Middleware;

use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Los módulos operativos requieren una empresa activa (el super admin debe elegir una). */
class EnsureCompanySelected
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(CurrentCompany::class)->id()) {
            return redirect()->route('admin.companies.index')->with('warning', 'Seleccione una empresa para continuar.');
        }

        return $next($request);
    }
}
