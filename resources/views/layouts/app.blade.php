@extends('layouts.base')

@section('body')
@php $user = auth()->user(); @endphp
<div x-data="{ open: false }" class="min-h-screen">
    {{-- Overlay móvil --}}
    <div x-show="open" x-cloak x-transition.opacity @click="open = false" class="fixed inset-0 z-30 bg-slate-900/60 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside :class="open ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-sidebar text-slate-300 transition-transform duration-200">
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-4">
            @if(!empty($currentCompany?->logo_url))
                <img src="{{ $currentCompany->logo_url }}" alt="" class="h-9 w-9 rounded-lg bg-white object-contain p-0.5">
            @else
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand text-sm font-bold text-white">{{ \Illuminate\Support\Str::of($currentCompany->name ?? 'HR')->substr(0, 2)->upper() }}</div>
            @endif
            <div class="min-w-0">
                <p class="truncate text-sm font-bold text-white">{{ $currentCompany->name ?? 'HR Media System' }}</p>
                <p class="truncate text-[11px] text-slate-400">Gestión de almacén y flotilla</p>
            </div>
            <button @click="open = false" class="ml-auto rounded p-1 text-slate-400 hover:text-white lg:hidden" aria-label="Cerrar menú"><x-icon name="x" /></button>
        </div>

        <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
            @foreach($menu as $section)
                <div>
                    @if($section['label'])
                        <p class="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $section['label'] }}</p>
                    @endif
                    <ul class="space-y-0.5">
                        @foreach($section['items'] as $item)
                            <li>
                                <a href="{{ route($item['route']) }}"
                                   class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $item['active'] ? 'bg-brand text-white shadow' : 'hover:bg-white/10 hover:text-white' }}">
                                    <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="border-t border-white/10 p-3 text-[11px] text-slate-500">
            Powered by <span class="font-semibold text-slate-300">HR Media System</span>
        </div>
    </aside>

    <div class="lg:pl-64">
        {{-- Barra superior --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6">
            <button @click="open = true" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Abrir menú"><x-icon name="menu" class="h-6 w-6" /></button>

            @stack('topbar')
            <div class="flex-1"></div>

            @if($user->is_super_admin)
                <form method="POST" action="{{ route('admin.companies.leave') }}">@csrf
                    <button class="btn-secondary btn-sm" title="Cambiar de empresa"><x-icon name="building" class="h-4 w-4" /> <span class="hidden sm:inline">{{ $currentCompany?->name ?? 'Elegir empresa' }}</span></button>
                </form>
            @endif

            <div x-data="{ menu: false }" class="relative">
                <button @click="menu = !menu" @click.outside="menu = false" class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-slate-100">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand text-xs font-bold text-white">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->first_name, 0, 1).\Illuminate\Support\Str::substr($user->last_name, 0, 1)) }}</span>
                    <span class="hidden text-left sm:block">
                        <span class="block text-sm font-semibold leading-tight text-slate-800">{{ $user->name }}</span>
                        <span class="block text-xs leading-tight text-slate-500">{{ $user->is_super_admin ? 'Super administrador' : ($user->role->name ?? '—') }}</span>
                    </span>
                </button>
                <div x-show="menu" x-cloak x-transition class="absolute right-0 mt-2 w-52 rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                    <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"><x-icon name="user" class="h-4 w-4" /> Mi perfil</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf
                        <button class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50"><x-icon name="logout" class="h-4 w-4" /> Cerrar sesión</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl p-4 pb-24 sm:p-6">
            <x-flash />
            @yield('content')
        </main>
    </div>
</div>
@endsection
