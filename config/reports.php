<?php

/*
| Centro de reportes modular. Cada sección agrupa los reportes de un módulo.
| 'reports' manual: pantallas propias previas. El resto de reportes de cada sección viene de App\Services\Reports\ReportRegistry.
| Todos los reportes se calculan desde el libro de movimientos / tablas históricas, nunca solo del stock actual.
*/
return [
    'sections' => [
        'warehouse' => [
            'label' => 'Reportes de Almacén', 'icon' => 'archive', 'route' => 'reports.warehouse', 'permission' => 'reports.inventory.view', 'phase' => 2,
            'description' => 'Existencias, movimientos y alertas del inventario.',
            'reports' => [
                ['label' => 'Inventario actual (vista de pantalla)', 'description' => 'Existencia por producto con fotos, cajas y unidades; imprimible.', 'route' => 'reports.inventory'],
                ['label' => 'Movimiento de inventario (vista de pantalla)', 'description' => 'Inicial + entradas − salidas ± ajustes = final por rango de fechas.', 'route' => 'reports.inventory-movement'],
            ],
        ],
        'fuel' => [
            'label' => 'Reportes de Gasoil', 'icon' => 'fuel', 'route' => 'reports.section', 'permission' => 'reports.fuel.view', 'phase' => 4,
            'description' => 'Recepciones, despachos y reserva de combustible.',
            'reports' => [],
        ],
        'containers' => [
            'label' => 'Reportes de Furgones', 'icon' => 'truck', 'route' => 'reports.section', 'permission' => 'reports.containers.view', 'phase' => 3,
            'description' => 'Operaciones por furgón y consumo de materiales.',
            'reports' => [],
        ],
        'vehicles' => [
            'label' => 'Reportes de Vehículos', 'icon' => 'truck', 'route' => 'reports.section', 'permission' => 'reports.vehicles.view', 'phase' => 4,
            'description' => 'Expediente, consumo y estado de la flotilla.',
            'reports' => [],
        ],
        'maintenance' => [
            'label' => 'Reportes de Mecánica / Mantenimiento', 'icon' => 'wrench', 'route' => 'reports.section', 'permission' => 'reports.maintenance.view', 'phase' => 5,
            'description' => 'Inspecciones, averías, reparaciones y piezas utilizadas.',
            'reports' => [],
        ],
        'tires' => [
            'label' => 'Reportes de Gomas', 'icon' => 'circle', 'route' => 'reports.section', 'permission' => 'reports.tires.view', 'phase' => 5,
            'description' => 'Gomas nuevas entregadas y usadas recibidas.',
            'reports' => [],
        ],
        'returnables' => [
            'label' => 'Reportes de Retornables', 'icon' => 'refresh', 'route' => 'reports.section', 'permission' => 'reports.returnables.view', 'phase' => 5,
            'description' => 'Tóner y otros materiales retornables.',
            'reports' => [],
        ],
        'audit' => [
            'label' => 'Reportes de Auditoría', 'icon' => 'shield', 'route' => 'reports.section', 'permission' => 'reports.audit.view', 'phase' => 6,
            'description' => 'Trazabilidad de acciones de usuarios.',
            'reports' => [
                ['label' => 'Bitácora de auditoría (vista de pantalla)', 'description' => 'Todas las acciones registradas, con filtros.', 'route' => 'admin.audit.index', 'permission' => 'audit.view'],
            ],
        ],
    ],
];
