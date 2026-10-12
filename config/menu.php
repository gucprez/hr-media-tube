<?php

/*
| Menú lateral. Un ítem aparece solo si su ruta existe (módulo instalado)
| y el usuario tiene el permiso indicado. 'super' => solo super administrador.
*/
return [
    ['label' => null, 'items' => [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'can' => 'dashboard.view'],
    ]],
    ['label' => 'Almacén', 'items' => [
        ['label' => 'Productos', 'route' => 'products.index', 'icon' => 'box', 'can' => 'products.view'],
        ['label' => 'Inventario', 'route' => 'inventory.index', 'icon' => 'archive', 'can' => 'inventory.view'],
        ['label' => 'Nueva entrada', 'route' => 'entries.create', 'icon' => 'plus', 'can' => 'inventory.entry'],
        ['label' => 'Nueva salida', 'route' => 'exits.create', 'icon' => 'arrow-up', 'can' => 'inventory.dispatch'],
        ['label' => 'Ajuste de inventario', 'route' => 'adjustments.create', 'icon' => 'scale', 'can' => 'inventory.adjust'],
        ['label' => 'Movimientos', 'route' => 'movements.index', 'icon' => 'arrows', 'can' => 'inventory.view'],
        ['label' => 'Categorías y unidades', 'route' => 'categories.index', 'icon' => 'tag', 'can' => 'products.view'],
    ]],
    ['label' => 'Operaciones', 'items' => [
        ['label' => 'Furgones', 'route' => 'containers.index', 'icon' => 'truck', 'can' => 'containers.view'],
        ['label' => 'Tickets', 'route' => 'tickets.index', 'icon' => 'ticket', 'can' => 'tickets.view'],
    ]],
    ['label' => 'Flotilla', 'items' => [
        ['label' => 'Gasoil', 'route' => 'fuel.index', 'icon' => 'fuel', 'can' => 'fuel.view'],
        ['label' => 'Vehículos', 'route' => 'vehicles.index', 'icon' => 'truck', 'can' => 'vehicles.view'],
        ['label' => 'Choferes', 'route' => 'drivers.index', 'icon' => 'users', 'can' => 'vehicles.view'],
        ['label' => 'Inspecciones', 'route' => 'inspections.index', 'icon' => 'clipboard', 'can' => 'vehicles.view'],
        ['label' => 'Averías', 'route' => 'incidents.index', 'icon' => 'alert', 'can' => 'vehicles.view'],
        ['label' => 'Mantenimiento', 'route' => 'maintenance.index', 'icon' => 'wrench', 'can' => 'maintenance.view'],
        ['label' => 'Gomas', 'route' => 'tires.index', 'icon' => 'circle', 'can' => 'tires.view'],
        ['label' => 'Retornables', 'route' => 'returnables.index', 'icon' => 'refresh', 'can' => 'returnables.view'],
    ]],
    ['label' => 'Reportes', 'items' => [
        ['label' => 'Centro de reportes', 'route' => 'reports.index', 'match' => ['reports.index'], 'icon' => 'chart', 'can' => ['reports.view', 'reports.inventory.view']],
        ['label' => 'Almacén', 'route' => 'reports.warehouse', 'match' => ['reports.warehouse', 'reports.inventory', 'reports.inventory-movement'], 'icon' => 'archive', 'can' => 'reports.inventory.view'],
        ['label' => 'Gasoil', 'route' => 'reports.section', 'params' => ['section' => 'fuel'], 'icon' => 'fuel', 'can' => 'reports.fuel.view'],
        ['label' => 'Furgones', 'route' => 'reports.section', 'params' => ['section' => 'containers'], 'icon' => 'truck', 'can' => 'reports.containers.view'],
        ['label' => 'Vehículos', 'route' => 'reports.section', 'params' => ['section' => 'vehicles'], 'icon' => 'truck', 'can' => 'reports.vehicles.view'],
        ['label' => 'Mecánica / Mantenimiento', 'route' => 'reports.section', 'params' => ['section' => 'maintenance'], 'icon' => 'wrench', 'can' => 'reports.maintenance.view'],
        ['label' => 'Gomas', 'route' => 'reports.section', 'params' => ['section' => 'tires'], 'icon' => 'circle', 'can' => 'reports.tires.view'],
        ['label' => 'Retornables', 'route' => 'reports.section', 'params' => ['section' => 'returnables'], 'icon' => 'refresh', 'can' => 'reports.returnables.view'],
        ['label' => 'Auditoría', 'route' => 'reports.section', 'params' => ['section' => 'audit'], 'icon' => 'shield', 'can' => 'reports.audit.view'],
    ]],
    ['label' => 'Administración', 'items' => [
        ['label' => 'Usuarios', 'route' => 'admin.users.index', 'icon' => 'users', 'can' => 'users.manage'],
        ['label' => 'Roles y permisos', 'route' => 'admin.roles.index', 'icon' => 'key', 'can' => 'roles.manage'],
        ['label' => 'Log de auditoría', 'route' => 'admin.audit.index', 'icon' => 'shield', 'can' => 'audit.view'],
        ['label' => 'Configuración', 'route' => 'admin.settings.company', 'icon' => 'cog', 'can' => 'settings.manage'],
    ]],
    ['label' => 'Plataforma', 'items' => [
        ['label' => 'Empresas', 'route' => 'admin.companies.index', 'icon' => 'building', 'super' => true],
    ]],
];
