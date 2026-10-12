<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f2a52">
    <title>@yield('title', 'Panel') · {{ $currentCompany->name ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --brand: {{ $currentCompany->primary_color ?? '#2563eb' }};
            --sidebar: {{ $currentCompany->secondary_color ?? '#0f2a52' }};
        }
    </style>
    @stack('head')
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
    @yield('body')
    @stack('scripts')
</body>
</html>
