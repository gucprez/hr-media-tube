@php $tabs = ['admin.settings.company' => 'Empresa', 'admin.settings.branches' => 'Sucursales y almacenes', 'admin.settings.numbering' => 'Numeración', 'admin.settings.checklist' => 'Checklist y tipos de vehículo']; @endphp
<h1 class="page-title mb-4">Configuración</h1>
<div class="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200">
    @foreach($tabs as $route => $label)
        <a href="{{ route($route) }}" class="whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium {{ request()->routeIs($route) ? 'border-brand text-brand' : 'border-transparent text-slate-500 hover:text-slate-800' }}">{{ $label }}</a>
    @endforeach
</div>
