<?php

/*
| Centro de reportes modular. Cada sección agrupa los reportes de un módulo.
| 'route' => disponible hoy; sin 'route' => planificado (se muestra como "Próximamente · Fase N").
| Todos los reportes se calculan desde el libro de movimientos / tablas históricas, nunca solo del stock actual.
*/
return [
    'sections' => [
        'warehouse' => [
            'label' => 'Reportes de Almacén', 'icon' => 'archive', 'route' => 'reports.warehouse', 'permission' => 'reports.inventory.view', 'phase' => 2,
            'description' => 'Existencias, movimientos y alertas del inventario.',
            'reports' => [
                ['label' => 'Inventario actual', 'description' => 'Existencia por producto en cajas y unidades, mínimo, estado y ubicación.', 'route' => 'reports.inventory'],
                ['label' => 'Movimiento de inventario', 'description' => 'Inicial + entradas − salidas ± ajustes = final, por cualquier rango de fechas.', 'route' => 'reports.inventory-movement'],
                ['label' => 'Stock bajo', 'description' => 'Productos por debajo de su mínimo.', 'route' => 'reports.inventory', 'params' => ['status' => 'low']],
                ['label' => 'Productos críticos', 'description' => 'Productos en nivel crítico.', 'route' => 'reports.inventory', 'params' => ['status' => 'critical']],
                ['label' => 'Productos agotados', 'description' => 'Productos sin existencia.', 'route' => 'reports.inventory', 'params' => ['status' => 'out']],
                ['label' => 'Entradas', 'description' => 'Detalle de entradas de almacén.', 'route' => 'movements.index', 'params' => ['type' => 'entry'], 'permission' => 'inventory.view'],
                ['label' => 'Salidas', 'description' => 'Detalle de salidas de almacén.', 'route' => 'movements.index', 'params' => ['type' => 'exit'], 'permission' => 'inventory.view'],
                ['label' => 'Historial de movimientos', 'description' => 'Libro completo de movimientos.', 'route' => 'movements.index', 'permission' => 'inventory.view'],
                ['label' => 'Inventario por categoría', 'description' => 'Resumen del inventario agrupado por categoría.'],
                ['label' => 'Consumo por producto', 'description' => 'Consumo mensual y tendencia por producto.'],
            ],
        ],
        'fuel' => [
            'label' => 'Reportes de Gasoil', 'icon' => 'fuel', 'route' => 'reports.section', 'permission' => 'reports.fuel.view', 'phase' => 4,
            'description' => 'Recepciones, despachos y reserva de combustible.',
            'reports' => [
                ['label' => 'Recepciones de gasoil'], ['label' => 'Despachos por vehículo'], ['label' => 'Consumo por período'],
                ['label' => 'Reserva histórica'], ['label' => 'Rendimiento por vehículo (km / galón)'],
            ],
        ],
        'containers' => [
            'label' => 'Reportes de Furgones', 'icon' => 'truck', 'route' => 'reports.section', 'permission' => 'reports.containers.view', 'phase' => 3,
            'description' => 'Operaciones por furgón y consumo de materiales.',
            'reports' => [
                ['label' => 'Historial por furgón'], ['label' => 'Consumo de materiales por furgón'], ['label' => 'Comparación entre furgones'], ['label' => 'Furgones por período'],
            ],
        ],
        'vehicles' => [
            'label' => 'Reportes de Vehículos', 'icon' => 'truck', 'route' => 'reports.section', 'permission' => 'reports.vehicles.view', 'phase' => 4,
            'description' => 'Expediente, consumo y estado de la flotilla.',
            'reports' => [
                ['label' => 'Historial por vehículo'], ['label' => 'Materiales y piezas por vehículo'], ['label' => 'Estado de la flotilla'], ['label' => 'Gasoil por vehículo'],
            ],
        ],
        'maintenance' => [
            'label' => 'Reportes de Mecánica / Mantenimiento', 'icon' => 'wrench', 'route' => 'reports.section', 'permission' => 'reports.maintenance.view', 'phase' => 5,
            'description' => 'Inspecciones, averías, reparaciones y piezas utilizadas.',
            'reports' => [
                ['label' => 'Inspecciones mensuales'], ['label' => 'Averías (abiertas / cerradas)'], ['label' => 'Mantenimientos realizados'], ['label' => 'Piezas utilizadas'],
            ],
        ],
        'tires' => [
            'label' => 'Reportes de Gomas', 'icon' => 'circle', 'route' => 'reports.section', 'permission' => 'reports.tires.view', 'phase' => 5,
            'description' => 'Gomas nuevas entregadas y usadas recibidas.',
            'reports' => [
                ['label' => 'Gomas entregadas'], ['label' => 'Gomas usadas recibidas'], ['label' => 'Devoluciones pendientes'], ['label' => 'Inventario de gomas usadas'],
            ],
        ],
        'returnables' => [
            'label' => 'Reportes de Retornables', 'icon' => 'refresh', 'route' => 'reports.section', 'permission' => 'reports.returnables.view', 'phase' => 5,
            'description' => 'Tóner y otros materiales retornables.',
            'reports' => [
                ['label' => 'Tóner entregados'], ['label' => 'Tóner retornados'], ['label' => 'Retornables pendientes'],
            ],
        ],
        'audit' => [
            'label' => 'Reportes de Auditoría', 'icon' => 'shield', 'route' => 'reports.section', 'permission' => 'reports.audit.view', 'phase' => 6,
            'description' => 'Trazabilidad de acciones de usuarios.',
            'reports' => [
                ['label' => 'Log de auditoría', 'description' => 'Todas las acciones registradas, con filtros.', 'route' => 'admin.audit.index', 'permission' => 'audit.view'],
                ['label' => 'Actividad por usuario'], ['label' => 'Ajustes y anulaciones'], ['label' => 'Accesos al sistema'],
            ],
        ],
    ],
];
