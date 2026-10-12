<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\InventoryController;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerMaterial;
use App\Models\Driver;
use App\Models\DocumentSequence;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\DocumentNumbering;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockService;
use App\Services\Operations\ContainerConsumption;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContainerController extends Controller
{
    public function __construct(protected QuantityConverter $converter) {}

    public function index(Request $request)
    {
        $containers = Container::with(['vehicle', 'driver'])->withCount('materials')
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->q).'%';
                $q->where(fn ($q) => $q->where('number', 'like', $like)->orWhere('driver_name', 'like', $like)->orWhere('conduce', 'like', $like)
                    ->orWhereHas('vehicle', fn ($v) => $v->where('ficha', 'like', $like)->orWhere('plate', 'like', $like)));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('vehicle'), fn ($q) => $q->where('vehicle_id', (int) $request->vehicle))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->date('to')))
            ->orderByDesc('date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('operations.containers.index', [
            'containers' => $containers,
            'vehicles' => Vehicle::orderBy('ficha')->get(['id', 'ficha', 'plate']),
        ]);
    }

    public function create()
    {
        return view('operations.containers.form', $this->formData(new Container(['date' => now(), 'status' => Container::OPEN, 'fuel_gallons' => 0])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $lines = $this->materialLines($data['materials'] ?? []);

        $container = DB::transaction(function () use ($data, $lines, $request) {
            $container = new Container(collect($data)->except('materials')->all());
            $container->number = ($data['number'] ?? null) ?: app(DocumentNumbering::class)->next('container');
            $container->status = Container::OPEN;
            $this->fillDriver($container);
            $container->save();
            $this->syncMaterials($container, $lines);
            Audit::log('container.created', "Furgón {$container->number} creado", $container, null, $this->snapshot($container));

            return $container;
        });

        return redirect()->route('containers.show', $container)->with('success', "Furgón {$container->number} creado.");
    }

    public function show(Container $container)
    {
        $container->load(['materials.product.unit', 'vehicle', 'driver', 'responsible', 'closer', 'voider', 'warehouse', 'movement', 'tickets']);

        $consumption = app(ContainerConsumption::class)->byProduct([$container->id]);
        $products = Product::withTrashed()->with('unit')->whereIn('id', $consumption->pluck('product_id'))->get()->keyBy('id');

        return view('operations.containers.show', [
            'container' => $container,
            'consumption' => $consumption,
            'products' => $products,
            'linked' => $container->linkedMovements()->with(['user', 'items'])->where('type', '!=', 'void')->when($container->movement_id, fn ($q) => $q->where('id', '!=', $container->movement_id))->orderBy('occurred_at')->get(),
            'history' => AuditLog::with('user')->where('auditable_type', $container->getMorphClass())->where('auditable_id', $container->id)->orderBy('created_at')->orderBy('id')->get(),
            'converter' => $this->converter,
        ]);
    }

    public function edit(Container $container)
    {
        abort_unless($container->isEditable(), 403, 'El furgón ya no admite cambios.');

        return view('operations.containers.form', $this->formData($container->load('materials.product.unit')));
    }

    public function update(Request $request, Container $container)
    {
        abort_unless($container->isEditable(), 403, 'El furgón ya no admite cambios.');
        $data = $this->validated($request, $container);
        $lines = $this->materialLines($data['materials'] ?? []);

        DB::transaction(function () use ($container, $data, $lines) {
            $before = $this->snapshot($container);
            $container->fill(collect($data)->except(['materials', 'number'])->all());
            if (! empty($data['number'])) {
                $container->number = $data['number'];
            }
            $this->fillDriver($container);
            $container->save();
            $this->syncMaterials($container, $lines);
            Audit::log('container.updated', "Furgón {$container->number} modificado", $container, $before, $this->snapshot($container->refresh()));
        });

        return redirect()->route('containers.show', $container)->with('success', 'Furgón actualizado.');
    }

    public function start(Container $container)
    {
        abort_unless($container->status === Container::OPEN, 403);
        $container->update(['status' => Container::IN_PROGRESS, 'started_at' => now()]);
        Audit::log('container.started', "Furgón {$container->number} en proceso", $container, ['status' => Container::OPEN], ['status' => Container::IN_PROGRESS]);

        return back()->with('success', 'Furgón en proceso.');
    }

    /** Cierra el furgón: descuenta TODOS sus materiales del inventario en un solo movimiento (o no descuenta nada). */
    public function close(Request $request, Container $container, StockService $stock)
    {
        abort_unless($container->isEditable(), 403, 'El furgón ya está cerrado o anulado.');
        $container->load('materials');
        if ($container->materials->isEmpty() && $container->fuel_gallons <= 0) {
            return back()->with('error', 'Agregue materiales o gasoil antes de cerrar el furgón.');
        }

        try {
            DB::transaction(function () use ($container, $stock, $request) {
                $movementId = null;
                if ($container->materials->isNotEmpty()) {
                    $movement = $stock->consume([
                        'warehouse_id' => $container->warehouse_id,
                        'container_id' => $container->id,
                        'vehicle_id' => $container->vehicle_id,
                        'destination' => 'Furgón '.$container->number,
                        'destination_type' => 'container',
                        'employee_name' => $container->driver_display,
                        'document_no' => $container->conduce,
                        'reference' => 'Furgón '.$container->number,
                        'reason' => 'Consumo de furgón',
                        'source_type' => $container->getMorphClass(), 'source_id' => $container->id,
                        'allow_negative' => $request->boolean('allow_negative'),
                    ], $container->materials->map(fn ($m) => ['product_id' => $m->product_id, 'boxes' => $m->boxes, 'units' => $m->units])->all(), $request->user());
                    $movementId = $movement->id;
                }

                $container->forceFill([
                    'status' => Container::CLOSED, 'closed_at' => now(), 'closed_by' => $request->user()->id, 'movement_id' => $movementId,
                ])->save();
                Audit::log('container.closed', "Furgón {$container->number} cerrado", $container, ['status' => $container->getOriginal('status')], ['status' => Container::CLOSED, 'movimiento' => $movementId]);

                app(\App\Services\Fleet\ContainerFuel::class)->onClose($container, $request->user());
            });
        } catch (InventoryException|\App\Services\Fuel\FuelException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('containers.show', $container)->with('success', "Furgón {$container->number} cerrado: materiales descontados del inventario.");
    }

    public function void(Request $request, Container $container, StockService $stock)
    {
        abort_if($container->status === Container::VOIDED, 403, 'El furgón ya está anulado.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], ['reason.required' => 'Indique el motivo de la anulación.', 'reason.min' => 'El motivo debe tener al menos 5 caracteres.']);

        try {
            DB::transaction(function () use ($container, $stock, $request, $data) {
                $voidMovementId = null;
                if ($container->status === Container::CLOSED && $container->movement_id) {
                    $reverse = $stock->reverse($container->movement, 'Anulación del furgón '.$container->number.': '.$data['reason'], $request->user(), $request->boolean('allow_negative'));
                    $voidMovementId = $reverse->id;
                }
                app(\App\Services\Fleet\ContainerFuel::class)->onVoid($container, $data['reason'], $request->user());

                // los tickets del furgón se anulan con él (nunca se borran)
                foreach (Ticket::where('container_id', $container->id)->where('status', '!=', 'voided')->get() as $ticket) {
                    $ticket->forceFill(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => 'Furgón anulado: '.$data['reason']])->save();
                    Audit::log('ticket.voided', "Ticket {$ticket->number} anulado por anulación del furgón {$container->number}", $ticket);
                }

                $old = $container->status;
                $container->forceFill([
                    'status' => Container::VOIDED, 'voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => $data['reason'],
                    'void_movement_id' => $voidMovementId,
                ])->save();
                Audit::log('container.voided', "Furgón {$container->number} anulado: {$data['reason']}", $container, ['status' => $old], ['status' => Container::VOIDED, 'motivo' => $data['reason']]);
            });
        } catch (InventoryException|\App\Services\Fuel\FuelException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('containers.show', $container)->with('success', 'Furgón anulado'.($container->void_movement_id ? ' y materiales devueltos al inventario.' : '.'));
    }

    // ---- internos --------------------------------------------------------------

    protected function formData(Container $container): array
    {
        $warehouses = Warehouse::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
        $oldLines = collect(old('materials', $container->exists ? $container->materials->map(fn ($m) => ['product_id' => $m->product_id, 'boxes' => $m->boxes ?: '', 'units' => $m->units ?: '']) : []))
            ->map(function ($l) {
                $p = Product::with(['unit', 'primaryImage'])->withSum('stocks as total_base', 'quantity_base')->find($l['product_id'] ?? 0);

                return $p ? ['product' => app(InventoryController::class)->payload($p), 'boxes' => $l['boxes'] ?? '', 'units' => $l['units'] ?? ''] : null;
            })->filter()->values();

        $seq = DocumentSequence::where('key', 'container')->first();

        return [
            'container' => $container,
            'warehouses' => $warehouses,
            'defaultWarehouse' => $warehouses->firstWhere('is_default', true) ?? $warehouses->first(),
            'drivers' => Driver::where('is_active', true)->orderBy('name')->get(),
            'vehicles' => Vehicle::whereNotIn('status', ['inactive'])->orderBy('ficha')->get(),
            'users' => User::where('is_active', true)->where('is_super_admin', false)->orderBy('name')->get(['id', 'name']),
            'oldLines' => $oldLines,
            'nextNumber' => $seq ? $seq->prefix.str_pad((string) $seq->next_number, $seq->padding, '0', STR_PAD_LEFT) : '0001',
        ];
    }

    protected function validated(Request $request, ?Container $container = null): array
    {
        $cid = app(CurrentCompany::class)->id();

        return $request->validate([
            'number' => ['nullable', 'string', 'max:30', Rule::unique('containers', 'number')->where('company_id', $cid)->ignore($container?->id)],
            'date' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $cid)->where('is_active', true)],
            'driver_id' => ['nullable', Rule::exists('drivers', 'id')->where('company_id', $cid)],
            'driver_name' => ['nullable', 'string', 'max:150'],
            'vehicle_id' => ['nullable', Rule::exists('vehicles', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'conduce' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
            'responsible_id' => ['nullable', Rule::exists('users', 'id')->where('company_id', $cid)],
            'fuel_gallons' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'materials' => ['nullable', 'array', 'max:100'],
            'materials.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'materials.*.boxes' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'materials.*.units' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]) + ['fuel_gallons' => (float) $request->input('fuel_gallons', 0)];
    }

    /** Convierte y valida cantidades con el servicio central; agrupa productos repetidos. */
    protected function materialLines(array $materials): array
    {
        $merged = [];
        foreach ($materials as $i => $line) {
            $product = Product::with('unit')->findOrFail($line['product_id']);
            try {
                $base = $this->converter->toBase($product, $line['boxes'] ?? 0, $line['units'] ?? 0);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(["materials.$i.units" => $e->getMessage()]);
            }
            if ($base <= 0) {
                throw ValidationException::withMessages(["materials.$i.units" => "Indique una cantidad mayor que cero para «{$product->name}»."]);
            }
            $merged[$product->id] = ['product' => $product, 'base' => ($merged[$product->id]['base'] ?? 0) + $base];
        }

        return $merged;
    }

    protected function syncMaterials(Container $container, array $lines): void
    {
        $container->materials()->delete();
        foreach ($lines as $line) {
            [$boxes, $units] = $this->converter->split($line['product'], $line['base']);
            ContainerMaterial::create([
                'container_id' => $container->id, 'product_id' => $line['product']->id, 'product_sku' => $line['product']->sku,
                'product_name' => $line['product']->name, 'boxes' => $boxes, 'units' => $units, 'quantity_base' => $line['base'],
            ]);
        }
    }

    protected function fillDriver(Container $container): void
    {
        if ($container->driver_id && ($driver = Driver::withTrashed()->find($container->driver_id))) {
            $container->driver_name = $container->driver_name ?: $driver->name;
            $container->phone = $container->phone ?: $driver->phone;
        }
        if ($container->vehicle_id && ! $container->driver_id && ! $container->driver_name) {
            $v = Vehicle::with('usualDriver')->find($container->vehicle_id);
            $container->driver_name = $v?->usualDriver?->name;
        }
    }

    protected function snapshot(Container $container): array
    {
        $container->loadMissing('materials');

        return $container->only(['number', 'date', 'driver_name', 'vehicle_id', 'conduce', 'phone', 'fuel_gallons', 'status', 'warehouse_id'])
            + ['materiales' => $container->materials->map(fn ($m) => ['producto' => $m->product_name, 'base' => (float) $m->quantity_base])->all()];
    }
}
