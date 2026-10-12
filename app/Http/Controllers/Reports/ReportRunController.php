<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Container;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\Reports\Period;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ReportResult;
use App\Services\Reports\XlsxExporter;
use App\Services\Tickets\TicketRenderer;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Ejecuta cualquier reporte del registro y lo entrega en pantalla, PDF o Excel. */
class ReportRunController extends Controller
{
    public function __construct(protected ReportRegistry $registry) {}

    public function run(Request $request, string $section, string $key)
    {
        $def = $this->registry->find($section, $key);
        abort_unless($def, 404);
        $user = $request->user();
        abort_unless($user->hasPermission(config("reports.sections.{$section}.permission")), 403);

        $period = Period::fromInput($request->only(['range', 'date', 'from', 'to']));
        $filters = $this->filters($request, $def);
        $result = ($def['run'])($period, $filters);
        $format = $request->query('format', 'html');

        if (in_array($format, ['xlsx', 'pdf'], true)) {
            abort_unless($user->hasPermission('reports.export'), 403);
            Audit::log('report.export', sprintf('Exportó «%s» (%s) — %s', $def['title'], strtoupper($format), $def['period'] ? $period->label() : 'a la fecha'), null, null, ['reporte' => $key, 'formato' => $format, 'filtros' => $filters]);

            return $format === 'xlsx' ? $this->xlsx($def, $period, $filters, $result) : $this->pdf($def, $period, $filters, $result);
        }

        return view('reports.run', [
            'def' => $def, 'period' => $period, 'filters' => $filters, 'result' => $result, 'section' => config("reports.sections.{$section}"),
            'sectionKey' => $section, 'options' => $this->options($def), 'canExport' => $user->hasPermission('reports.export'), 'meta' => $this->meta($def, $period, $filters),
        ]);
    }

    protected function filters(Request $request, array $def): array
    {
        $out = [];
        foreach ($def['filters'] as $name => $spec) {
            $v = $request->query($name);
            if (! is_string($v) || $v === '') {
                continue;
            }
            if ($spec['type'] === 'entity' && ! ctype_digit($v)) {
                continue;
            }
            if ($spec['type'] === 'select' && ! array_key_exists($v, $spec['options'])) {
                continue;
            }
            $out[$name] = Str::limit($v, 100, '');
        }

        return $out;
    }

    /** Opciones de los filtros de tipo entidad (solo de la empresa actual: los modelos ya están aislados). */
    protected function options(array $def): array
    {
        $o = [];
        foreach ($def['filters'] as $name => $spec) {
            if ($spec['type'] !== 'entity') {
                continue;
            }
            $o[$name] = match ($name) {
                'vehicle' => Vehicle::orderBy('ficha')->get()->mapWithKeys(fn ($v) => [$v->id => $v->ficha.($v->plate ? ' · '.$v->plate : '')])->all(),
                'product' => Product::orderBy('name')->get(['id', 'name', 'sku'])->mapWithKeys(fn ($p) => [$p->id => $p->name.' ('.$p->sku.')'])->all(),
                'category' => Category::orderBy('name')->pluck('name', 'id')->all(),
                'warehouse' => Warehouse::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
                'container' => Container::orderByDesc('date')->orderByDesc('id')->limit(300)->get()->mapWithKeys(fn ($c) => [$c->id => '#'.$c->number.' · '.$c->date->format('d/m/Y')])->all(),
                'user' => User::where('company_id', app(CurrentCompany::class)->id())->orderBy('name')->pluck('name', 'id')->all(),
                default => [],
            };
        }

        return $o;
    }

    protected function meta(array $def, Period $period, array $filters): array
    {
        $meta = $def['period'] ? ['Período' => $period->rangeLabel().' · '.$period->label()] : ['Información' => 'A la fecha '.now()->format('d/m/Y H:i')];
        $options = $this->options($def);
        foreach ($filters as $name => $value) {
            $spec = $def['filters'][$name];
            $meta[$spec['label']] = $spec['type'] === 'select' ? ($spec['options'][$value] ?? $value) : ($spec['type'] === 'entity' ? ($options[$name][$value] ?? $value) : $value);
        }

        return $meta;
    }

    protected function fileName(array $def, string $ext): string
    {
        return Str::slug($def['key']).'-'.now()->format('Ymd-His').'.'.$ext;
    }

    protected function xlsx(array $def, Period $period, array $filters, ReportResult $result)
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt');
        (new XlsxExporter)->write($path, app(CurrentCompany::class)->get()->name, $def['title'], $this->meta($def, $period, $filters), $result);

        return response()->download($path, $this->fileName($def, 'xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    protected function pdf(array $def, Period $period, array $filters, ReportResult $result)
    {
        $company = app(CurrentCompany::class)->get();
        $landscape = count($result->columns) > 6;
        $pdf = Pdf::loadView('reports.pdf', [
            'def' => $def, 'result' => $result, 'meta' => $this->meta($def, $period, $filters), 'company' => $company,
            'logo' => app(TicketRenderer::class)->logoDataUri($company), 'user' => auth()->user(),
        ])->setPaper('letter', $landscape ? 'landscape' : 'portrait')->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);

        return $pdf->download($this->fileName($def, 'pdf'));
    }
}
