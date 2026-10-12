@extends('layouts.app')
@section('title', 'Configuración')
@section('content')
@include('admin.settings._tabs')
<form method="POST" action="{{ route('admin.settings.company.update') }}" enctype="multipart/form-data" class="space-y-6">
    @csrf @method('PUT')
    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Datos de la empresa</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="name" label="Nombre comercial" :value="$company->name" required />
            <x-field name="legal_name" label="Razón social" :value="$company->legal_name" />
            <x-field name="rnc" label="RNC" :value="$company->rnc" />
            <x-field name="phone" label="Teléfono" :value="$company->phone" />
            <x-field name="email" label="Correo" type="email" :value="$company->email" />
            <x-field name="address" label="Dirección" :value="$company->address" />
        </div>
    </div>
    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Marca</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-3 flex items-center gap-4">
                @if($company->logo_url)<img src="{{ $company->logo_url }}" class="h-16 w-16 rounded-lg border border-slate-200 object-contain" alt="Logo">@endif
                <div class="flex-1">
                    <label class="label" for="logo">Logo (PNG/JPG/WebP, máx. 2 MB)</label>
                    <input id="logo" type="file" name="logo" accept="image/*" class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium">
                    @error('logo')<p class="field-error">{{ $message }}</p>@enderror
                    @if($company->logo_url)<label class="mt-2 flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300"> Quitar logo actual</label>@endif
                </div>
            </div>
            <div><label class="label" for="primary_color">Color principal (acciones)</label><input id="primary_color" type="color" name="primary_color" value="{{ old('primary_color', $company->primary_color) }}" class="h-10 w-full rounded-lg border border-slate-300"></div>
            <div><label class="label" for="secondary_color">Color del menú lateral</label><input id="secondary_color" type="color" name="secondary_color" value="{{ old('secondary_color', $company->secondary_color) }}" class="h-10 w-full rounded-lg border border-slate-300"></div>
        </div>
    </div>
    <div class="card card-body space-y-4">
        <h2 class="font-semibold text-slate-900">Operación e impresión</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="settings[ticket_footer]" label="Pie de tickets y reportes" :value="$company->setting('ticket_footer')" />
            <x-field name="settings[critical_ratio]" label="Nivel crítico (fracción del mínimo)" type="number" step="0.05" min="0.05" max="1" :value="$company->setting('critical_ratio', 0.5)" hint="Un producto es CRÍTICO cuando su existencia ≤ mínimo × este valor." />
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="settings[allow_negative_stock]" value="1" class="rounded border-slate-300" @checked($company->setting('allow_negative_stock'))> Permitir stock negativo con autorización especial</label>
    </div>
    <button class="btn-primary">Guardar configuración</button>
</form>
@endsection
