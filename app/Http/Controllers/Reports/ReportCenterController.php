<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportRegistry;
use Illuminate\Http\Request;

class ReportCenterController extends Controller
{
    /** Centro de reportes: tarjetas por módulo según permisos. */
    public function index(Request $request)
    {
        return view('reports.index', ['sections' => $this->sections($request)]);
    }

    public function warehouse(Request $request)
    {
        return $this->section($request, 'warehouse');
    }

    public function section(Request $request, string $section)
    {
        $sections = $this->sections($request);
        abort_unless(isset($sections[$section]), 404);

        return view('reports.section', ['key' => $section, 'section' => $sections[$section]]);
    }

    /** Secciones visibles para el usuario (con cada reporte marcado como disponible/planificado). */
    protected function sections(Request $request): array
    {
        $user = $request->user();
        $out = [];
        foreach (config('reports.sections') as $key => $section) {
            if (! $user->hasPermission($section['permission'])) {
                continue;
            }
            $section['key'] = $key;
            $section['url'] = $key === 'warehouse' ? route('reports.warehouse') : route('reports.section', $key);
            $registry = collect(app(ReportRegistry::class)->forSection($key))->map(fn ($d) => [
                'label' => $d['title'], 'description' => $d['description'], 'route' => 'reports.run', 'params' => [$key, $d['key']], 'registry' => true,
            ])->values()->all();
            $section['reports'] = collect(array_merge($section['reports'], $registry))->map(function ($r) use ($user, $section) {
                $available = isset($r['route']) && \Illuminate\Support\Facades\Route::has($r['route']) && $user->hasPermission($r['permission'] ?? $section['permission']);
                $r['url'] = $available ? route($r['route'], $r['params'] ?? []) : null;
                $r['available'] = $available;
                $r['phase'] = $section['phase'];

                return $r;
            })->all();
            $out[$key] = $section;
        }

        return $out;
    }
}
