<?php

namespace App\Http\Controllers\Fuel;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\FuelDispatch;
use App\Models\FuelLedger;
use App\Models\FuelReceipt;
use App\Models\Vehicle;
use App\Services\Audit;
use App\Services\DocumentNumbering;
use App\Services\Fuel\FuelException;
use App\Services\Fuel\FuelService;
use App\Services\ImageStore;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FuelController extends Controller
{
    public const RECEIPT_PHOTOS = ['meter' => 'Medidor', 'truck' => 'Camión', 'invoice' => 'Factura', 'conduce' => 'Conduce'];

    public function __construct(protected FuelService $fuel) {}

    // ---- panel -----------------------------------------------------------------

    public function index()
    {
        $tank = $this->fuel->tank();
        $monthStart = now()->startOfMonth()->toDateString();

        return view('fuel.index', [
            'tank' => $tank,
            'receipts' => FuelReceipt::with('user')->orderByDesc('received_at')->orderByDesc('id')->limit(6)->get(),
            'dispatches' => FuelDispatch::with(['vehicle', 'user'])->orderByDesc('dispatched_at')->orderByDesc('id')->limit(8)->get(),
            'month' => [
                'received' => (float) FuelReceipt::whereNull('voided_at')->whereDate('received_at', '>=', $monthStart)->sum('gallons_received'),
                'dispatched' => (float) FuelDispatch::whereNull('voided_at')->whereDate('dispatched_at', '>=', $monthStart)->sum('gallons'),
            ],
            'lastReading' => $this->lastReading(),
        ]);
    }

    public function ledger(Request $request)
    {
        $lines = FuelLedger::with(['user', 'vehicle'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('from'), fn ($q) => $q->where('occurred_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('occurred_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('fuel.ledger', ['lines' => $lines, 'tank' => $this->fuel->tank()]);
    }

    // ---- recepciones -------------------------------------------------------------

    public function receipts(Request $request)
    {
        $receipts = FuelReceipt::with('user')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('receipt_number', 'like', '%'.$request->q.'%')->orWhere('supplier', 'like', '%'.$request->q.'%')->orWhere('truck_plate', 'like', '%'.$request->q.'%')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('received_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('received_at', '<=', $request->date('to')))
            ->orderByDesc('received_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('fuel.receipts.index', compact('receipts'));
    }

    public function createReceipt()
    {
        $seq = \App\Models\DocumentSequence::where('key', 'fuel_receipt')->first();

        return view('fuel.receipts.form', [
            'tank' => $this->fuel->tank(),
            'nextNumber' => $seq ? $seq->prefix.str_pad((string) $seq->next_number, $seq->padding, '0', STR_PAD_LEFT) : 'REC-0001',
            'photoTypes' => self::RECEIPT_PHOTOS,
        ]);
    }

    public function storeReceipt(Request $request)
    {
        $cid = app(CurrentCompany::class)->id();
        $data = $request->validate([
            'receipt_number' => ['nullable', 'string', 'max:30', Rule::unique('fuel_receipts', 'receipt_number')->where('company_id', $cid)],
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'meter_start' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'meter_end' => ['required', 'numeric', 'gt:meter_start', 'max:999999999999'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'truck_plate' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ] + $this->photoRules(array_keys(self::RECEIPT_PHOTOS)), ['meter_end.gt' => 'El metro final debe ser mayor que el metro inicial.']);

        $stored = [];
        try {
            $receipt = DB::transaction(function () use ($data, $request, &$stored) {
                $receipt = $this->fuel->receive($data, $request->user());
                $this->attach($request, $receipt, array_keys(self::RECEIPT_PHOTOS), $stored);

                return $receipt;
            });
        } catch (FuelException $e) {
            $this->cleanup($stored);

            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->cleanup($stored);
            throw $e;
        }

        return redirect()->route('fuel.receipts.show', $receipt)->with('success', sprintf('Recepción %s registrada: +%s galones. Reserva: %s gal.', $receipt->receipt_number, number_format($receipt->gallons_received, 1), number_format($receipt->reserve_after, 1)));
    }

    public function showReceipt(FuelReceipt $receipt)
    {
        $receipt->load(['user', 'voider', 'attachments', 'tank']);

        return view('fuel.receipts.show', ['receipt' => $receipt, 'photoTypes' => self::RECEIPT_PHOTOS]);
    }

    public function voidReceipt(Request $request, FuelReceipt $receipt)
    {
        return $this->doVoid($request, $receipt);
    }

    // ---- despachos ---------------------------------------------------------------

    public function dispatches(Request $request)
    {
        $dispatches = FuelDispatch::with(['vehicle', 'user', 'container'])
            ->when($request->filled('vehicle'), fn ($q) => $q->where('vehicle_id', (int) $request->vehicle))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('dispatched_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('dispatched_at', '<=', $request->date('to')))
            ->orderByDesc('dispatched_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('fuel.dispatches.index', ['dispatches' => $dispatches, 'vehicles' => Vehicle::orderBy('ficha')->get(['id', 'ficha'])]);
    }

    public function createDispatch(Request $request)
    {
        return view('fuel.dispatches.form', [
            'tank' => $this->fuel->tank(),
            'vehicles' => Vehicle::where('status', '!=', 'inactive')->orderBy('ficha')->get(),
            'lastReading' => $this->lastReading(),
            'selected' => $request->integer('vehicle'),
            'containers' => \App\Models\Container::with('vehicle')->whereIn('status', ['open', 'in_progress'])->orderByDesc('date')->orderByDesc('id')->get(),
            'selectedContainer' => $request->integer('container'),
        ]);
    }

    public function storeDispatch(Request $request)
    {
        $cid = app(CurrentCompany::class)->id();
        $data = $request->validate([
            'dispatched_at' => ['required', 'date', 'before_or_equal:today'],
            'vehicle_id' => ['required', Rule::exists('vehicles', 'id')->where('company_id', $cid)->whereNull('deleted_at')],
            'container_id' => ['nullable', Rule::exists('containers', 'id')->where('company_id', $cid)->whereNull('deleted_at')->whereIn('status', ['open', 'in_progress'])],
            'reading_start' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'reading_end' => ['required', 'numeric', 'gt:reading_start', 'max:999999999999'],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ] + $this->photoRules(['photos']), ['reading_end.gt' => 'La lectura final debe ser mayor que la lectura inicial.', 'vehicle_id.required' => 'Seleccione el vehículo (ficha).']);

        $stored = [];
        try {
            $dispatch = DB::transaction(function () use ($data, $request, &$stored) {
                $d = $this->fuel->dispatch($data, $request->user());
                $this->attach($request, $d, ['photos'], $stored, 'photo');
                // con furgón: sus galones pasan a los datos del furgón y al cerrarlo solo se descuenta lo que falte
                if (! empty($data['container_id']) && ($container = \App\Models\Container::find($data['container_id']))) {
                    app(\App\Services\Fleet\ContainerFuel::class)->afterDispatch($container, $d, $request->user());
                }

                return $d;
            });
        } catch (FuelException $e) {
            $this->cleanup($stored);

            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->cleanup($stored);
            throw $e;
        }

        return redirect()->route('fuel.dispatches.index')->with('success', sprintf('Despacho registrado: %s gal a %s. Reserva: %s gal.', number_format($dispatch->gallons, 1), $dispatch->vehicle->ficha, number_format($dispatch->reserve_after, 1)));
    }

    public function voidDispatch(Request $request, FuelDispatch $dispatch)
    {
        if ($dispatch->origin === 'container' && $dispatch->container && $dispatch->container->status !== 'voided') {
            return back()->with('error', 'Este despacho proviene de un furgón: se anula desde el furgón.');
        }

        return $this->doVoid($request, $dispatch);
    }

    // ---- ajuste de reserva y tanque -------------------------------------------------

    public function adjustForm()
    {
        return view('fuel.adjust', ['tank' => $this->fuel->tank()]);
    }

    public function adjust(Request $request)
    {
        $data = $request->validate(['physical' => ['required', 'numeric', 'min:0', 'max:99999999'], 'reason' => ['required', 'string', 'min:5', 'max:200']],
            ['reason.required' => 'El ajuste exige un motivo.', 'reason.min' => 'El motivo debe tener al menos 5 caracteres.', 'physical.required' => 'Indique la medición física en galones.']);
        try {
            $line = $this->fuel->adjust((float) $data['physical'], $data['reason'], $request->user());
        } catch (FuelException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fuel.index')->with('success', sprintf('Reserva ajustada a %s gal (diferencia %s gal).', number_format($line->reserve_after, 1), number_format($line->delta_gallons, 1)));
    }

    public function updateTank(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'capacity_gallons' => ['nullable', 'numeric', 'min:0', 'max:99999999']]);
        $tank = $this->fuel->tank();
        $original = $tank->getAttributes();
        $tank->update($data);
        Audit::changes('updated', $tank, $original, 'Tanque de combustible actualizado');

        return back()->with('success', 'Tanque actualizado.');
    }

    // ---- internos -----------------------------------------------------------------

    protected function doVoid(Request $request, FuelReceipt|FuelDispatch $record)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], ['reason.required' => 'Indique el motivo de la anulación.', 'reason.min' => 'El motivo debe tener al menos 5 caracteres.']);
        try {
            $this->fuel->void($record, $data['reason'], $request->user());
        } catch (FuelException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Registro anulado y reserva corregida.');
    }

    protected function lastReading(): ?float
    {
        $last = FuelDispatch::whereNull('voided_at')->whereNotNull('reading_end')->orderByDesc('dispatched_at')->orderByDesc('id')->first();

        return $last?->reading_end;
    }

    protected function photoRules(array $fields): array
    {
        $rules = [];
        foreach ($fields as $f) {
            $rules[$f] = ['nullable', 'array', 'max:3'];
            $rules[$f.'.*'] = ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'];
        }

        return $rules;
    }

    /** Guarda las fotos de cada colección como adjuntos privados del registro. */
    protected function attach(Request $request, $record, array $fields, array &$stored, ?string $forceCollection = null): void
    {
        $store = app(ImageStore::class);
        foreach ($fields as $field) {
            foreach (array_filter((array) $request->file($field)) as $file) {
                $info = $store->store($file, 'companies/'.$record->company_id.'/fuel');
                $stored[] = $info;
                Attachment::create([
                    'attachable_type' => $record->getMorphClass(), 'attachable_id' => $record->id, 'collection' => $forceCollection ?? $field,
                    'disk' => ImageStore::DISK, 'path' => $info['path'], 'original_name' => $info['original_name'], 'mime' => $info['mime'],
                    'size' => $info['size'], 'user_id' => $request->user()->id,
                ]);
            }
        }
    }

    protected function cleanup(array $stored): void
    {
        foreach ($stored as $info) {
            app(ImageStore::class)->delete($info['path'], $info['thumb_path'] ?? null);
        }
    }
}
