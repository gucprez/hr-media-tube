<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Services\Audit;
use App\Services\DocumentNumbering;
use App\Services\Inventory\QuantityConverter;
use App\Services\Tickets\TicketRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function __construct(protected QuantityConverter $converter) {}

    public function index(Request $request)
    {
        $tickets = Ticket::with('creator')->withCount('items')
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->q).'%';
                $q->where(fn ($q) => $q->where('number', 'like', $like)->orWhere('container_number', 'like', $like)->orWhere('driver_name', 'like', $like)
                    ->orWhere('conduce', 'like', $like)->orWhere('ficha', 'like', $like));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('issued_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('issued_at', '<=', $request->date('to')))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        return view('operations.tickets.index', compact('tickets'));
    }

    public function create(Request $request, Container $container)
    {
        abort_if($container->status === Container::VOIDED, 403, 'El furgón está anulado.');
        $type = $request->query('type') === Ticket::FUEL ? Ticket::FUEL : Ticket::MATERIALS;
        $container->load(['materials.product.unit', 'vehicle', 'driver']);

        return view('operations.tickets.form', [
            'container' => $container,
            'type' => $type,
            'source' => $this->source($request),
            'items' => $type === Ticket::MATERIALS ? $this->materialItems($container, $this->source($request)) : $this->fuelDefaults($container),
        ]);
    }

    public function store(Request $request, Container $container)
    {
        abort_if($container->status === Container::VOIDED, 403, 'El furgón está anulado.');
        $type = $request->input('type') === Ticket::FUEL ? Ticket::FUEL : Ticket::MATERIALS;
        $container->load(['materials.product.unit', 'vehicle', 'driver']);

        $rules = [
            'received_by' => ['nullable', 'string', 'max:150'],
            'dispatched_by' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'issued_at' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
        ];
        if ($type === Ticket::FUEL) {
            $rules += [
                'rows' => ['required', 'array', 'min:1', 'max:30'],
                'rows.*.ficha' => ['required', 'string', 'max:30'],
                'rows.*.odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
                'rows.*.gallons' => ['required', 'numeric', 'gt:0', 'max:99999'],
            ];
        }
        $data = $request->validate($rules, ['rows.required' => 'Agregue al menos una fila de gasoil.', 'rows.*.gallons.gt' => 'Los galones deben ser mayores que cero.']);

        $items = $type === Ticket::MATERIALS ? $this->materialItems($container, $this->source($request)) : [];
        if ($type === Ticket::MATERIALS && ! $items) {
            return back()->with('error', $this->source($request) === 'used' ? 'Todavía no se ha descontado ningún material del inventario para este furgón (registre una salida hacia el furgón o ciérrelo).' : 'El furgón no tiene materiales registrados: agréguelos antes de emitir el ticket.')->withInput();
        }

        $ticket = DB::transaction(function () use ($container, $type, $data, $items, $request) {
            $ticket = Ticket::create([
                'container_id' => $container->id, 'vehicle_id' => $container->vehicle_id, 'type' => $type,
                'number' => app(DocumentNumbering::class)->next($type === Ticket::FUEL ? 'ticket_fuel' : 'ticket_materials'),
                'status' => 'issued', 'issued_at' => $data['issued_at'], 'container_number' => $container->number,
                'driver_name' => $container->driver_display, 'conduce' => $container->conduce, 'ficha' => $container->vehicle?->ficha,
                'phone' => $container->phone, 'dispatched_by' => ($data['dispatched_by'] ?? null) ?: $request->user()->name,
                'received_by' => $data['received_by'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id,
            ]);
            if ($type === Ticket::MATERIALS) {
                foreach ($items as $item) {
                    TicketItem::create(['ticket_id' => $ticket->id] + $item);
                }
            } else {
                foreach ($data['rows'] as $row) {
                    TicketItem::create([
                        'ticket_id' => $ticket->id, 'description' => 'Gasoil', 'ficha' => $row['ficha'],
                        'odometer' => $row['odometer'] ?? null, 'gallons' => $row['gallons'],
                    ]);
                }
            }
            Audit::log('ticket.created', "{$ticket->type_label} {$ticket->number} emitido (furgón {$container->number})", $ticket, null, [
                'numero' => $ticket->number, 'furgon' => $container->number, 'lineas' => $ticket->items()->count(),
            ]);

            return $ticket;
        });

        return redirect()->route('tickets.show', $ticket)->with('success', "{$ticket->type_label} {$ticket->number} emitido.");
    }

    public function show(Ticket $ticket)
    {
        $ticket->load(['items', 'creator', 'voider', 'container']);

        return view('operations.tickets.show', [
            'ticket' => $ticket,
            'history' => AuditLog::with('user')->where('auditable_type', $ticket->getMorphClass())->where('auditable_id', $ticket->id)->orderBy('created_at')->orderBy('id')->get(),
            'plan' => app(TicketRenderer::class)->plan($ticket, (int) request('copies', 4)),
        ]);
    }

    /** Vista de impresión HTML (1, 2 o 4 tickets en hoja carta). Cada apertura cuenta como impresión; las siguientes son «REIMPRESIÓN». */
    public function print(Request $request, Ticket $ticket, TicketRenderer $renderer)
    {
        if ($ticket->isVoided()) {
            return redirect()->route('tickets.show', $ticket)->with('error', 'El ticket está anulado y no puede imprimirse.');
        }
        $plan = $renderer->plan($ticket->load('items'), (int) $request->query('copies', 4));
        $reprint = $ticket->print_count > 0;
        $this->registerPrint($ticket, $plan['copies'], 'impresión');

        return view('operations.tickets.print', ['ticket' => $ticket, 'plan' => $plan, 'reprint' => $reprint, 'company' => app(\App\Support\CurrentCompany::class)->get(), 'auto' => $request->boolean('auto', true)]);
    }

    public function pdf(Request $request, Ticket $ticket, TicketRenderer $renderer)
    {
        if ($ticket->isVoided()) {
            return redirect()->route('tickets.show', $ticket)->with('error', 'El ticket está anulado y no puede generarse en PDF.');
        }
        $plan = $renderer->plan($ticket->load('items'), (int) $request->query('copies', 4));
        $reprint = $ticket->print_count > 0;
        $this->registerPrint($ticket, $plan['copies'], 'PDF');

        $pdf = $renderer->pdf($ticket, $plan['copies'], $reprint);

        return $pdf->download('ticket-'.strtolower($ticket->type).'-'.$ticket->number.'.pdf');
    }

    public function void(Request $request, Ticket $ticket)
    {
        abort_if($ticket->isVoided(), 403, 'El ticket ya está anulado.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], ['reason.required' => 'Indique el motivo de la anulación.', 'reason.min' => 'El motivo debe tener al menos 5 caracteres.']);

        $ticket->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => $data['reason']])->save();
        Audit::log('ticket.voided', "{$ticket->type_label} {$ticket->number} anulado: {$data['reason']}", $ticket, ['status' => 'issued'], ['status' => 'voided', 'motivo' => $data['reason']]);

        return back()->with('success', "Ticket {$ticket->number} anulado (se conserva en el historial).");
    }

    // ---- internos --------------------------------------------------------------

    protected function registerPrint(Ticket $ticket, int $copies, string $how): void
    {
        $ticket->increment('print_count');
        $ticket->forceFill(['print_layout' => (string) $copies, 'last_printed_at' => now()])->save();
        Audit::log($ticket->print_count > 1 ? 'ticket.reprinted' : 'ticket.printed', ($ticket->print_count > 1 ? 'Reimpresión' : 'Impresión')." ({$how}, {$copies} por hoja) de {$ticket->type_label} {$ticket->number}", $ticket);
    }

    /** @return array<int,array<string,mixed>> líneas ya convertidas (snapshot) del ticket de materiales */
    /**
     * Materiales del ticket: por producto, lo mayor entre lo listado en el furgón y lo ya descontado del inventario para él
     * (libro: cierre + salidas dirigidas al furgón, netas de anulaciones). El cierre solo descuenta lo que falta, así que
     * lo listado ya incluye lo descontado antes: sumar ambos lo contaría dos veces.
     */
    /** «all» = listado + gastado; «used» = solo lo ya descontado del inventario (gastado) para este furgón. */
    protected function source(Request $request): string
    {
        return $request->input('source', $request->query('source')) === 'used' ? 'used' : 'all';
    }

    protected function materialItems(Container $container, string $source = 'all'): array
    {
        $qty = [];
        foreach ($source === 'used' ? [] : $container->materials as $m) {
            $qty[$m->product_id] = ($qty[$m->product_id] ?? 0) + $m->quantity_base;
        }
        foreach (app(\App\Services\Operations\ContainerConsumption::class)->byProduct([$container->id]) as $r) {
            $qty[$r->product_id] = max($qty[$r->product_id] ?? 0, $r->quantity_base);
        }
        $products = \App\Models\Product::withTrashed()->with('unit')->whereIn('id', array_keys($qty))->get()->keyBy('id');

        return collect($qty)->filter(fn ($q) => $q > 0)->map(function ($q, $id) use ($products) {
            $p = $products[$id];

            return [
                'product_id' => $id,
                'description' => $p->name,
                'quantity_label' => $this->converter->format($p, $q),
                'total_units' => $q,
                'unit_label' => $this->converter->unitLabel($p, $q),
            ];
        })->values()->all();
    }

    protected function fuelDefaults(Container $container): array
    {
        return [['ficha' => $container->vehicle?->ficha, 'odometer' => $container->vehicle?->odometer ?: null, 'gallons' => $container->fuel_gallons ?: null]];
    }
}
