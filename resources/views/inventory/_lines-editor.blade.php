{{-- Editor de líneas de producto (Alpine docLines). Variables: $field (nombre del arreglo), $title. Requiere un padre con x-data="docLines(...)". --}}
    <div class="card card-body space-y-3">
        <div class="flex items-center justify-between"><h2 class="font-semibold text-slate-900">{{ $title ?? 'Productos' }}</h2><span class="text-xs text-slate-500" x-text="lines.length + ' línea(s)'"></span></div>

        <template x-for="(line, i) in lines" :key="line.key">
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-3" :data-line="i">
                <input type="hidden" :name="`{{ $field }}[${i}][product_id]`" :value="line.product ? line.product.id : ''">

                {{-- selector de producto --}}
                <div x-show="!line.product" class="relative" @click.outside="line.open = false">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input type="search" x-model="line.q" @input="search(i)" @focus="search(i)" placeholder="Buscar producto por nombre o código…" autocomplete="off" class="input !pl-9" aria-label="Producto">
                    <div x-show="line.open" x-cloak class="absolute left-0 right-0 z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl">
                        <template x-for="r in line.results" :key="r.id">
                            <button type="button" @click="pick(i, r)" class="flex w-full items-center gap-3 border-b border-slate-100 px-3 py-2 text-left last:border-0 hover:bg-slate-50">
                                <img x-show="r.thumb" :src="r.thumb" class="h-10 w-10 rounded-md border border-slate-200 object-cover" alt="">
                                <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-slate-800" x-text="r.name"></span><span class="block truncate text-xs text-slate-500" x-text="r.sku + ' · Hay ' + r.stock_label"></span></span>
                                <span class="badge-gray" x-text="r.status_label"></span>
                            </button>
                        </template>
                        <p x-show="!line.results.length" class="px-3 py-4 text-center text-sm text-slate-500">Sin resultados</p>
                    </div>
                </div>

                <div x-show="line.product" x-cloak class="space-y-3">
                    <div class="flex items-center gap-3">
                        <img x-show="line.product && line.product.thumb" :src="line.product ? line.product.thumb : ''" class="h-12 w-12 rounded-lg border border-slate-200 object-cover" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-900" x-text="line.product ? line.product.name : ''"></p>
                            <p class="truncate text-xs text-slate-500" x-text="line.product ? line.product.sku + ' · En este almacén: ' + (line.preview ? line.preview.current_label : line.product.stock_label) : ''"></p>
                        </div>
                        <button type="button" @click="line.product = null; line.preview = null" class="text-xs font-medium text-brand">Cambiar</button>
                        <button type="button" @click="remove(i)" class="rounded-lg p-2 text-red-600 hover:bg-red-50" aria-label="Quitar línea"><x-icon name="trash" class="h-5 w-5" /></button>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div x-show="showBoxes(line)">
                            <label class="label capitalize" x-text="(mode === 'adjustment' ? 'Conteo: ' : '') + (line.product ? line.product.box_label : '')"></label>
                            <input type="number" inputmode="numeric" min="0" step="1" x-model="line.boxes" @input="refresh(i)" :name="`{{ $field }}[${i}][boxes]`" data-qty class="input" placeholder="0">
                        </div>
                        <div x-show="showUnits(line)" :class="showBoxes(line) ? '' : 'col-span-2'">
                            <label class="label capitalize" x-text="(mode === 'adjustment' ? 'Conteo: ' : '') + (line.product ? line.product.unit_label : '')"></label>
                            <input type="number" inputmode="decimal" min="0" step="any" x-model="line.units" @input="refresh(i)" :name="`{{ $field }}[${i}][units]`" :data-qty="!showBoxes(line)" class="input" placeholder="0">
                        </div>
                    </div>

                    <div x-show="line.preview" x-cloak class="rounded-lg bg-white px-3 py-2 text-xs ring-1 ring-slate-200">
                        <template x-if="line.preview && line.preview.error"><p class="font-medium text-red-600" x-text="line.preview.error"></p></template>
                        <template x-if="line.preview && !line.preview.error && mode !== 'adjustment'">
                            <p class="text-slate-600"><b class="text-slate-900" x-text="line.preview.quantity_label"></b> · quedará en <b class="text-slate-900" x-text="line.preview.result_label"></b> <span class="badge-gray ml-1" x-text="line.preview.status"></span></p>
                        </template>
                        <template x-if="line.preview && !line.preview.error && mode === 'adjustment'">
                            <p class="text-slate-600">Sistema: <b class="text-slate-900" x-text="line.preview.current_label"></b> · Conteo: <b class="text-slate-900" x-text="line.preview.result_label"></b> · Diferencia: <b :class="line.preview.delta_base < 0 ? 'text-red-600' : (line.preview.delta_base > 0 ? 'text-emerald-700' : 'text-slate-900')" x-text="line.preview.delta_label"></b></p>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <button type="button" @click="add()" class="btn-secondary w-full sm:w-auto"><x-icon name="plus" class="h-4 w-4" /> Agregar otro producto</button>
    </div>

