<?php

/*
|--------------------------------------------------------------------------
| Catálogo de permisos
|--------------------------------------------------------------------------
| Fuente única de verdad. `php artisan permissions:sync` (y el seeder) lo
| replican en la tabla `permissions`. Los roles son plantillas editables:
| el acceso real se evalúa SIEMPRE por permiso individual en el backend.
*/
return [

    'groups' => [
        'Panel' => [
            'dashboard.view' => 'Ver dashboard',
            'search.use' => 'Usar búsqueda global',
        ],
        'Productos' => [
            'products.view' => 'Ver productos',
            'products.create' => 'Crear productos',
            'products.edit' => 'Editar productos',
            'products.delete' => 'Eliminar productos',
        ],
        'Inventario' => [
            'inventory.view' => 'Ver inventario y movimientos',
            'inventory.entry' => 'Registrar entradas',
            'inventory.dispatch' => 'Registrar salidas',
            'inventory.adjust' => 'Registrar ajustes',
            'inventory.negative' => 'Autorizar stock negativo',
            'inventory.void' => 'Anular movimientos',
        ],
        'Furgones' => [
            'containers.view' => 'Ver furgones',
            'containers.create' => 'Crear / editar furgones',
            'containers.close' => 'Cerrar furgones',
            'containers.void' => 'Anular furgones',
        ],
        'Tickets' => [
            'tickets.view' => 'Ver tickets',
            'tickets.create' => 'Generar tickets',
            'tickets.print' => 'Imprimir / reimprimir tickets',
            'tickets.void' => 'Anular tickets',
        ],
        'Combustible' => [
            'fuel.view' => 'Ver combustible',
            'fuel.receive' => 'Registrar recepciones de gasoil',
            'fuel.dispatch' => 'Registrar despachos de gasoil',
            'fuel.void' => 'Anular recepciones / despachos',
            'fuel.adjust' => 'Ajustar la reserva de gasoil (medición física)',
        ],
        'Flotilla' => [
            'vehicles.view' => 'Ver vehículos',
            'vehicles.edit' => 'Crear / editar vehículos',
            'vehicles.inspect' => 'Realizar inspecciones',
            'incidents.report' => 'Reportar averías',
            'incidents.manage' => 'Gestionar averías',
            'maintenance.view' => 'Ver mantenimientos',
            'maintenance.manage' => 'Registrar mantenimientos',
        ],
        'Gomas y retornables' => [
            'tires.view' => 'Ver gomas',
            'tires.manage' => 'Entregar / recibir gomas',
            'returnables.view' => 'Ver retornables',
            'returnables.manage' => 'Gestionar retornables',
        ],
        'Reportes' => [
            'reports.view' => 'Acceder al centro de reportes',
            'reports.inventory.view' => 'Reportes de almacén',
            'reports.fuel.view' => 'Reportes de gasoil',
            'reports.containers.view' => 'Reportes de furgones',
            'reports.vehicles.view' => 'Reportes de vehículos',
            'reports.maintenance.view' => 'Reportes de mecánica / mantenimiento',
            'reports.tires.view' => 'Reportes de gomas',
            'reports.returnables.view' => 'Reportes de retornables',
            'reports.audit.view' => 'Reportes de auditoría',
            'reports.export' => 'Exportar reportes (Excel / PDF)',
        ],
        'Administración' => [
            'users.manage' => 'Administrar usuarios',
            'roles.manage' => 'Administrar roles y permisos',
            'settings.manage' => 'Configuración de la empresa',
            'audit.view' => 'Ver auditoría',
        ],
    ],

    /*
    | Plantillas de rol. '*' = todos los permisos; 'view' = todos los *.view
    | más reports.view; otros se listan explícitamente.
    */
    'roles' => [
        'company_admin' => [
            'name' => 'Administrador de empresa',
            'description' => 'Control total de su empresa.',
            'permissions' => ['*'],
        ],
        'warehouse_manager' => [
            'name' => 'Encargado de almacén',
            'description' => 'Productos, inventario, furgones y tickets.',
            'permissions' => [
                'dashboard.view', 'search.use', 'products.*', 'inventory.*', 'containers.*', 'tickets.*',
                'fuel.view', 'vehicles.view', 'tires.*', 'returnables.*', 'maintenance.view', 'reports.*',
            ],
        ],
        'operator' => [
            'name' => 'Operador',
            'description' => 'Registra entradas, salidas y furgones.',
            'permissions' => [
                'dashboard.view', 'search.use', 'products.view', 'inventory.view', 'inventory.entry', 'inventory.dispatch',
                'containers.view', 'containers.create', 'tickets.view', 'tickets.create', 'tickets.print', 'vehicles.view',
            ],
        ],
        'supervisor' => [
            'name' => 'Supervisor',
            'description' => 'Supervisa operaciones, cierra furgones y anula con motivo.',
            'permissions' => [
                'dashboard.view', 'search.use', 'products.view', 'inventory.view', 'inventory.void', 'containers.*', 'tickets.*',
                'fuel.view', 'vehicles.view', 'vehicles.inspect', 'incidents.*', 'maintenance.view', 'tires.view', 'returnables.view', 'reports.*',
            ],
        ],
        'fuel' => [
            'name' => 'Combustible',
            'description' => 'Recepción y despacho de gasoil.',
            'permissions' => ['dashboard.view', 'search.use', 'fuel.*', 'vehicles.view', 'tickets.view', 'tickets.print', 'reports.view', 'reports.fuel.view'],
        ],
        'maintenance' => [
            'name' => 'Mantenimiento',
            'description' => 'Inspecciones, averías, mantenimientos y gomas.',
            'permissions' => [
                'dashboard.view', 'search.use', 'products.view', 'inventory.view', 'inventory.dispatch', 'vehicles.*', 'incidents.*',
                'maintenance.*', 'tires.*', 'returnables.*', 'reports.view', 'reports.vehicles.view', 'reports.maintenance.view', 'reports.tires.view', 'reports.returnables.view', 'reports.inventory.view',
            ],
        ],
        'auditor' => [
            'name' => 'Auditor',
            'description' => 'Consulta, reportes, exportación y auditoría.',
            'permissions' => ['view', 'search.use', 'reports.export', 'audit.view', 'reports.audit.view'],
        ],
        'read_only' => [
            'name' => 'Solo consulta',
            'description' => 'Ve inventario y reportes. No crea, edita ni elimina.',
            'permissions' => ['view', 'search.use'],
        ],
    ],
];
