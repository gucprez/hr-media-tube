<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\InventoryMovement as Movement;
use App\Models\InventoryMovementItem as Line;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Audit;
use App\Services\ImageStore;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\QuantityConverter;
use App\Services\Inventory\StockService;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MovementController extends Controller
{
    public const ADJUST_REASONS = ['Conteo físico', 'Producto dañado', 'Pérdida o faltante', 'Vencimiento', 'Error de registro anterior', 'Sobrante encontrado', 'Otro'];

    public function __construct(protected QuantityConverter $converter) {}

    // ---- libro de movimientos --------------------------------------------------

    public function index(Request $request)
    {
        $lines = Line::with(['movement.user', 'warehouse', 'product.unit'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->q).'%';
                $q->where(fn ($q) => $q->where('product_name', 'like', $like)->orWhere('product_sku', 'like', $like)
                    ->orWhereHas('movement', fn ($m) => $m->where('number', 'like', $like)->orWhere('document_no', 'like', $like)->orWhere('reference', 'like', $like)));
            })
            ->when($request->filled('product'), fn ($q) => $q->where('product_id', (int) $request->product))
            ->when($request->filled('type'), fn ($q) => $q->whereIn('type', $this->typeGroup($request->type)))
            ->when($request->filled('warehouse'), fn ($q) => $q->where('warehouse_id', (int) $request->warehouse))
            ->when($request->filled('user'), fn ($q) => $q->whereHas('movement', fn ($m) => $m->where('user_id', (int) $request->user)))
            ->when($request->filled('from'), fn ($q) => $q->where('occurred_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('occurred_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('inventory.movements.index', [
            'lines' => $lines,
            'warehouses' => Warehouse::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'converter' => $this->converter,
        ]);
    }

    protected function typeGroup(string $type): array
    {
        return match ($type) {
            'entry' => [Line::ENTRY],
            'exit' => [Line::EXIT],
            'adjustment' => [Line::ADJUSTMENT_IN, Line::ADJUSTMENT_OUT],
            default => array_key_exists($type, Line::LABELS) ? [$type] : ['__none__'],
        };
    }

    public function show(Movement $movement)
    {
        $movement->load(['items.product.unit', 'warehouse.branch', 'user', 'attachments', 'container', 'vehicle', 'reversalOf', 'replaces', 'replacedBy']);

        return view('inventory.movements.show', ['movement' => $movement, 'converter' => $this->converter]);
    }

    /** Anula un documento de inventario generando su reverso (nada se borra). Los consumos de furgón se anulan desde el furgón. */
    public function void(Request $request, Movement $movement, StockService $stock)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], ['reason.required' => 'Indique el motivo de la anulación.', 'reason.min' => 'El motivo debe tener al menos 5 caracteres.']);

        if ($movement->type === Movement::TYPE_VOID || $movement->isVoided()) {
            return back()->with('error', 'Este documento ya está anulado o es una anulación.');
        }
        if ($movement->type === Movement::TYPE_CONTAINER && $movement->container_id) {
            return back()->with('error', 'El consumo de un furgón se anula desde el propio furgón.');
        }

        try {
            $reverse = $stock->reverse($movement, $data['reason'], $request->user(), $request->boolean('allow_negative'));
        } catch (InventoryException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('movements.show', $movement)->with('success', "Documento anulado con {$reverse->number}.");
    }

    // ---- formularios -----------------------------------------------------------

    public function createEntry()
    {
        return $this->form('entry');
    }

    public function createExit()
    {
        return $this->form('exit');
    }

    public function createAdjustment()
    {
        return $this->form('adjustment');
    }

    public function storeEntry(Request $request, StockService $service)
    {
        $data = $this->validateDocument($request, 'entry');

        return $this->post($request, 'entry', $data, fn () => $service->entry($this->header($data, 'entry'), $data['lines'], $request->user()));
    }

    public function storeExit(Request $request, StockService $service)
    {
        $data = $this->validateDocument($request, 'exit');

        return $this->post($request, 'exit', $data, fn () => $service->exit($this->header($data, 'exit'), $data['lines'], $request->user()));
    }

    public function storeAdjustment(Request $request, StockService $service)
    {
        $data = $this->validateDocument($request, 'adjustment');

        return $this->post($request, 'adjustment', $data, fn () => $service->adjust($this->header($data, 'adjustment'), $data['lines'], $request->user()));
    }

    // ---- editar = anular el original y crear uno nuevo corregido ----------------

    public function edit(Movement $movement)
    {
        if ($reason = $movement->editBlockReason()) {
            return redirect()->route('movements.show', $movement)->with('error', $reason);
        }
        $movement->load(['items', 'container']);

        return $this->form($movement->type, $movement);
    }

    /**
     * El libro de inventario NUNCA se modifica: «editar» crea un documento nuevo con los datos corregidos y anula el original
     * (ligados entre sí, con motivo y auditoría), todo en una sola transacción. Si algo falla no queda nada a medias.
     * El orden evita falsos rechazos: en una ENTRADA se registra primero la nueva y luego se anula la original (solo importa la
     * existencia final); en una SALIDA se anula primero la original (devuelve el stock) y luego se registra la nueva.
     */
    public function update(Request $request, Movement $movement, StockService $service)
    {
        if ($reason = $movement->editBlockReason()) {
            return redirect()->route('movements.show', $movement)->with('error', $reason);
        }
        $why = $request->validate(
            ['edit_reason' => ['required', 'string', 'min:5', 'max:200']],
            ['edit_reason.required' => 'Indique el motivo de la edición.', 'edit_reason.min' => 'El motivo debe tener al menos 5 caracteres.'],
        )['edit_reason'];

        $mode = $movement->type;
        $data = $this->validateDocument($request, $mode);
        $header = $this->header($data, $mode) + ['replaces_id' => $movement->id];
        $user = $request->user();

        return $this->post($request, $mode, $data, function () use ($service, $movement, $mode, $header, $data, $why, $user) {
            try {
                if ($mode === Movement::TYPE_ENTRY) {
                    $new = $service->entry($header, $data['lines'], $user);
                    $service->reverse($movement, 'Edición: '.$why, $user, (bool) ($data['allow_negative'] ?? false));
                } else {
                    $service->reverse($movement, 'Edición: '.$why, $user, false);
                    $new = $service->exit($header, $data['lines'], $user);
                }
            } catch (InventoryException $e) {
                throw new InventoryException(str_replace('No se puede anular:', 'No se puede editar:', $e->getMessage()));
            }

            // Las fotos y documentos del original (conduce, factura) siguen con el documento corregido.
            foreach ($movement->attachments as $a) {
                Attachment::create([
                    'attachable_type' => $new->getMorphClass(), 'attachable_id' => $new->id, 'collection' => $a->collection,
                    'disk' => $a->disk, 'path' => $a->path, 'original_name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size, 'user_id' => $a->user_id,
                ]);
            }
            Audit::log('inventory.edit', sprintf('Edición de %s %s: lo reemplaza %s — %s', $movement->type_label, $movement->number, $new->number, $why), $new,
                ['documento_original' => $movement->number], ['documento_nuevo' => $new->number, 'motivo' => $why]);

            return $new;
        }, fn (Movement $new) => "{$movement->type_label} {$movement->number} editada: se anuló y se creó {$new->number} con los datos corregidos.");
    }

    // ---- internos --------------------------------------------------------------

    protected function form(string $mode, ?Movement $movement = null)
    {
        $warehouses = Warehouse::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
        $user = auth()->user();
        $default = $warehouses->firstWhere('branch_id', $user->branch_id && $warehouses->where('branch_id', $user->branch_id)->count() === 1 ? $user->branch_id : null)
            ?? $warehouses->firstWhere('is_default', true) ?? $warehouses->first();
        if ($movement) {
            $default = $warehouses->firstWhere('id', $movement->warehouse_id) ?? $default;
        }

        $preselect = request()->integer('product');
        $initial = old('lines', $movement
            ? $movement->items->map(fn ($i) => ['product_id' => $i->product_id, 'boxes' => $i->boxes ?: '', 'units' => $i->units ?: ''])->all()
            : ($preselect ? [['product_id' => $preselect]] : []));
        $oldLines = collect($initial)->map(function ($l) {
            $p = Product::with(['unit', 'primaryImage'])->withSum('stocks as total_base', 'quantity_base')->find($l['product_id'] ?? 0);

            return $p ? ['product' => app(InventoryController::class)->payload($p), 'boxes' => $l['boxes'] ?? '', 'units' => $l['units'] ?? ''] : null;
        })->filter()->values();

        $company = app(CurrentCompany::class)->get();

        return view('inventory.document-form', [
            'mode' => $mode,
            'warehouses' => $warehouses,
            'defaultWarehouse' => $default,
            'oldLines' => $oldLines,
            'reasons' => self::ADJUST_REASONS,
            'containers' => $mode === 'exit' ? \App\Models\Container::whereIn('status', ['open', 'in_progress'])->orderByDesc('date')->get(['id', 'number', 'date']) : collect(),
            'canNegative' => ($mode === 'exit' || $movement !== null) && $company?->setting('allow_negative_stock') && $user->hasPermission('inventory.negative'),
            'movement' => $movement,
        ]);
    }

    protected function validateDocument(Request $request, string $mode): array
    {
        $cid = app(CurrentCompany::class)->id();
        \App\Support\LineInput::dropEmpty($request, 'lines');
        $rules = [
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $cid)->where('is_active', true)],
            'date' => ['required', 'date', 'before_or_equal:today', 'after:'.now()->subYears(2)->toDateString()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'lines.*.boxes' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.units' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
        $messages = ['lines.required' => 'Agregue al menos un producto.', 'lines.min' => 'Agregue al menos un producto.'];

        if ($mode === 'entry') {
            $rules += ['supplier' => ['nullable', 'string', 'max:150'], 'document_no' => ['nullable', 'string', 'max:60']];
        } elseif ($mode === 'exit') {
            $rules += [
                'destination' => ['required_unless:destination_type,container', 'nullable', 'string', 'max:150'],
                'destination_type' => ['required', Rule::in(['other', 'employee', 'container'])],
                'container_id' => ['required_if:destination_type,container', 'nullable', Rule::exists('containers', 'id')->where('company_id', $cid)->whereNull('deleted_at')->whereIn('status', ['open', 'in_progress'])],
                'employee_name' => ['nullable', 'required_if:destination_type,employee', 'string', 'max:150'],
                'reason' => ['required', 'string', 'max:150'],
                'allow_negative' => ['boolean'],
            ];
            $messages += ['destination.required' => 'Indique el destino de la salida.', 'reason.required' => 'Indique el motivo de la salida.'];
        } else {
            $rules['reason'] = ['required', 'string', Rule::in(self::ADJUST_REASONS)];
            $rules['notes'] = ['required', 'string', 'min:5', 'max:2000'];
            $messages += ['reason.required' => 'El ajuste exige un motivo.', 'notes.required' => 'El ajuste exige una observación.', 'notes.min' => 'La observación debe explicar el ajuste (mín. 5 caracteres).'];
        }

        $data = $request->validate($rules, $messages);

        if ($mode === 'adjustment') {
            foreach ($data['lines'] as $i => $line) {
                if (($line['boxes'] ?? '') === '' && ($line['units'] ?? '') === '') {
                    throw ValidationException::withMessages(["lines.$i.units" => 'Indique el conteo físico (puede ser 0).']);
                }
            }
        }
        $data['allow_negative'] = $request->boolean('allow_negative');
        if ($mode === 'exit' && ($data['destination_type'] ?? null) === 'container') {
            $data['destination'] = 'Furgón '.\App\Models\Container::find($data['container_id'])?->number;
        }

        return $data;
    }

    protected function header(array $data, string $mode): array
    {
        $date = \Carbon\Carbon::parse($data['date']);
        $occurredAt = $date->isToday() ? now() : $date->setTime(12, 0);

        return [
            'warehouse_id' => (int) $data['warehouse_id'],
            'occurred_at' => $occurredAt,
            'supplier' => $data['supplier'] ?? null,
            'document_no' => $data['document_no'] ?? null,
            'destination' => $data['destination'] ?? null,
            'destination_type' => $data['destination_type'] ?? null,
            'container_id' => ($data['destination_type'] ?? null) === 'container' ? ($data['container_id'] ?? null) : null,
            'employee_name' => $data['employee_name'] ?? null,
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'allow_negative' => $data['allow_negative'] ?? false,
        ];
    }

    /** Registra el documento y sus adjuntos en una sola transacción; si algo falla no queda nada a medias. */
    protected function post(Request $request, string $mode, array $data, \Closure $action, ?\Closure $message = null)
    {
        $stored = [];
        try {
            $movement = DB::transaction(function () use ($request, $action, &$stored) {
                $movement = $action();
                $store = app(ImageStore::class);
                foreach (array_filter((array) $request->file('files')) as $file) {
                    $info = $store->store($file, 'companies/'.$movement->company_id.'/movements');
                    $stored[] = $info;
                    Attachment::create([
                        'attachable_type' => $movement->getMorphClass(), 'attachable_id' => $movement->id, 'collection' => 'document',
                        'disk' => ImageStore::DISK, 'path' => $info['path'], 'original_name' => $info['original_name'],
                        'mime' => $info['mime'], 'size' => $info['size'], 'user_id' => $request->user()->id,
                    ]);
                }

                return $movement;
            });
        } catch (InventoryException $e) {
            $this->cleanup($stored);

            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->cleanup($stored);
            throw $e;
        }

        return redirect()->route('movements.show', $movement)->with('success', $message ? $message($movement) : Movement::TYPES[$mode].' '.$movement->number.' registrada correctamente.');
    }

    protected function cleanup(array $stored): void
    {
        foreach ($stored as $info) {
            app(ImageStore::class)->delete($info['path'], $info['thumb_path'] ?? null);
        }
    }
}
