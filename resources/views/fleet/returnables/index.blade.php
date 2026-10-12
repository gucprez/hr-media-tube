@extends('layouts.app')
@section('title', 'Retornables')
@section('content')
<div class="mb-4"><h1 class="page-title">Materiales retornables</h1><p class="text-sm text-slate-500">Tóner y otros materiales que deben regresar: entregado → usado → retornado / recargable / desechado.</p></div>
<div class="grid gap-6 xl:grid-cols-[1fr_380px]">
    <div class="space-y-6">
        <div class="card"><h2 class="border-b border-slate-100 px-5 py-3 font-semibold text-slate-900">Pendientes de devolver ({{ $pending->count() }})</h2>
            @forelse($pending as $r)
                @php $v = $vehicles->firstWhere('id', $r->vehicle_id); $p = $products->firstWhere('id', $r->product_id); @endphp
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3 last:border-0" x-data="{ open: false }">
                    <div class="min-w-0"><p class="font-semibold text-slate-900">{{ $r->product_name }} <span class="badge-yellow">{{ rtrim(rtrim(number_format($r->outstanding, 3), '0'), '.') }} pendiente(s)</span></p><p class="text-sm text-slate-500">En poder de <b>{{ $v?->ficha ?? $r->holder }}</b> · entregado {{ rtrim(rtrim(number_format($r->delivered, 3), '0'), '.') }}, usado {{ rtrim(rtrim(number_format($r->used, 3), '0'), '.') }}</p></div>
                    @can('returnables.manage')<button type="button" @click="open = !open" class="btn-secondary btn-sm">Registrar estado</button>
                    <form x-show="open" x-cloak method="POST" action="{{ route('returnables.transition') }}" class="grid w-full gap-2 border-t border-slate-100 pt-3 sm:grid-cols-[1fr_100px_1fr_auto]">@csrf
                        <input type="hidden" name="product_id" value="{{ $r->product_id }}"><input type="hidden" name="vehicle_id" value="{{ $r->vehicle_id }}"><input type="hidden" name="holder" value="{{ $r->holder }}">
                        <select name="state" class="input" aria-label="Estado"><option value="used">Usado (aún no devuelto)</option><option value="returned">Retornado</option><option value="refillable">Recargable</option><option value="discarded">Desechado</option></select>
                        <input name="quantity" type="number" step="any" min="0.001" value="1" class="input" aria-label="Cantidad"><input name="notes" placeholder="Observaciones" class="input" aria-label="Observaciones"><button class="btn-primary">Guardar</button></form>@endcan
                </div>
            @empty<p class="px-5 py-8 text-center text-sm text-slate-500">No hay retornables pendientes.</p>@endforelse
        </div>
        <div class="card"><div class="flex items-center justify-between border-b border-slate-100 px-5 py-3"><h2 class="font-semibold text-slate-900">Historial</h2>
            <form method="GET"><select name="product" class="input !min-h-9 !w-48 text-sm" onchange="this.form.submit()" aria-label="Producto"><option value="">Todos</option>@foreach($products as $p)<option value="{{ $p->id }}" @selected(request('product') == $p->id)>{{ $p->name }}</option>@endforeach</select></form></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Fecha</th><th>Producto</th><th>Estado</th><th class="text-right">Cant.</th><th>Portador</th><th>Usuario</th></tr></thead><tbody>
                @forelse($history as $h)<tr><td class="whitespace-nowrap text-xs">{{ $h->occurred_at->format('d/m/Y H:i') }}</td><td>{{ $h->product_name }}</td><td><span class="{{ in_array($h->state, ['returned', 'refillable']) ? 'badge-green' : ($h->state === 'discarded' ? 'badge-red' : ($h->state === 'used' ? 'badge-yellow' : 'badge-blue')) }}">{{ $states[$h->state] }}</span></td><td class="text-right">{{ rtrim(rtrim(number_format($h->quantity, 3), '0'), '.') }}</td><td>{{ $h->vehicle?->ficha ?? $h->holder }}</td><td class="text-xs">{{ $h->user?->name }}</td></tr>@empty<tr><td colspan="6" class="py-8 text-center text-slate-500">Sin movimientos.</td></tr>@endforelse
            </tbody></table></div><div class="px-4">{{ $history->links() }}</div></div>
    </div>
    <div class="space-y-4">
        <div class="card card-body"><h2 class="mb-2 font-semibold text-slate-900">Existencia de retornables nuevos</h2>
            @forelse($products as $p)<div class="flex items-center justify-between border-b border-slate-100 py-2 text-sm last:border-0"><a href="{{ route('products.show', $p) }}" class="text-slate-800 hover:text-brand">{{ $p->name }}</a><span class="font-bold">{{ $converter->format($p, $p->totalBase()) }}</span></div>@empty<p class="text-sm text-slate-500">No hay productos retornables. En Productos active «Retornable».</p>@endforelse</div>
        @can('returnables.manage')
        <form method="POST" action="{{ route('returnables.deliver') }}" class="card card-body space-y-3">@csrf
            <h2 class="font-semibold text-slate-900">Entregar retornable</h2>
            <div><label class="label">Producto</label><select name="product_id" class="input" required><option value="">Seleccione…</option>@foreach($products as $p)<option value="{{ $p->id }}" @selected(old('product_id') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
            <div class="grid grid-cols-2 gap-3"><x-field name="quantity" label="Cantidad" type="number" step="any" min="0.001" value="1" required /><div><label class="label">Almacén</label><select name="warehouse_id" class="input">@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div></div>
            <div><label class="label">Vehículo (opcional)</label><select name="vehicle_id" class="input"><option value="">—</option>@foreach($vehicles as $v)<option value="{{ $v->id }}">{{ $v->ficha }}</option>@endforeach</select></div>
            <x-field name="holder" label="o persona / área que lo recibe" />
            <x-field name="notes" label="Observaciones" />
            @if($canNegative)<label class="flex items-start gap-2 text-sm text-red-700"><input type="checkbox" name="allow_negative" value="1" class="mt-0.5 rounded border-red-300"> Autorización especial (existencia negativa).</label>@endif
            <button class="btn-primary w-full">Entregar y descontar inventario</button>
        </form>
        @endcan
    </div>
</div>
@endsection
