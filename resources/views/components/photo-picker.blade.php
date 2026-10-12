@props(['name' => 'photos', 'label' => 'Fotografías', 'multiple' => true, 'max' => 8, 'accept' => 'image/*', 'documents' => false, 'hint' => null])
{{-- Cámara del celular (capture), galería o archivo. Las fotos se comprimen en el navegador antes de enviarse. --}}
<div x-data="photoPicker({ multiple: {{ $multiple ? 'true' : 'false' }}, max: {{ $max }} })" class="space-y-3">
    <div class="flex items-center justify-between gap-2">
        <label class="label !mb-0">{{ $label }}</label>
        <span class="text-xs text-slate-500" x-text="files.length + ' / ' + max"></span>
    </div>
    <div class="flex flex-wrap gap-2">
        <label class="btn-secondary cursor-pointer">
            <x-icon name="camera" class="h-5 w-5" /> Tomar foto
            <input type="file" accept="image/*" capture="environment" class="sr-only" @change="add($event)" :disabled="files.length >= max">
        </label>
        <label class="btn-secondary cursor-pointer">
            <x-icon name="photo" class="h-5 w-5" /> {{ $documents ? 'Elegir foto o PDF' : 'Elegir de la galería' }}
            <input type="file" accept="{{ $documents ? 'image/*,application/pdf' : 'image/*' }}" @if($multiple) multiple @endif class="sr-only" @change="add($event)" :disabled="files.length >= max">
        </label>
        <span x-show="busy" x-cloak class="self-center text-xs text-slate-500">Procesando…</span>
    </div>
    {{-- input real que viaja con el formulario --}}
    <input type="file" x-ref="real" name="{{ $name }}[]" multiple class="hidden" tabindex="-1">
    <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6" x-show="files.length" x-cloak>
        <template x-for="(f, i) in files" :key="i">
            <div class="relative overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                <template x-if="previews[i]"><img :src="previews[i]" class="aspect-square w-full object-cover" alt=""></template>
                <template x-if="!previews[i]"><div class="flex aspect-square w-full items-center justify-center text-xs font-semibold text-slate-500">PDF</div></template>
                <button type="button" @click="remove(i)" class="absolute right-1 top-1 flex h-7 w-7 items-center justify-center rounded-full bg-slate-900/70 text-white" aria-label="Quitar"><x-icon name="x" class="h-4 w-4" /></button>
                <p class="truncate bg-white px-1.5 py-0.5 text-[10px] text-slate-500" x-text="size(f)"></p>
            </div>
        </template>
    </div>
    @if($hint)<p class="text-xs text-slate-500">{{ $hint }}</p>@endif
    @error($name.'.*')<p class="field-error">{{ $message }}</p>@enderror
    @error($name)<p class="field-error">{{ $message }}</p>@enderror
</div>
