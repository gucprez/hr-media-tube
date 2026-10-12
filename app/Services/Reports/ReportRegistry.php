<?php

namespace App\Services\Reports;

use App\Services\Reports\Definitions\{AuditReports, ContainerReports, FuelReports, MaintenanceReports, ReturnableReports, TireReports, VehicleReports, WarehouseReports};

/** Catálogo central de reportes por sección. */
class ReportRegistry
{
    public const SETS = [
        WarehouseReports::class, FuelReports::class, ContainerReports::class, VehicleReports::class,
        MaintenanceReports::class, TireReports::class, ReturnableReports::class, AuditReports::class,
    ];

    public const LABELS = ['vehicle' => 'Vehículo', 'product' => 'Producto', 'category' => 'Categoría', 'warehouse' => 'Almacén', 'container' => 'Furgón', 'user' => 'Usuario'];

    /** @var array<string,array<string,array>>|null */
    protected ?array $cache = null;

    /** @return array<string,array<string,array>> sección => clave => definición */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach (self::SETS as $class) {
                $set = app($class);
                foreach ($set->definitions() as $def) {
                    $def['section'] = $set->section();
                    $def['filters'] = $this->normalize($def['filters'] ?? []);
                    $this->cache[$set->section()][$def['key']] = $def;
                }
            }
        }

        return $this->cache;
    }

    public function find(string $section, string $key): ?array
    {
        return $this->all()[$section][$key] ?? null;
    }

    /** @return array<string,array> */
    public function forSection(string $section): array
    {
        return $this->all()[$section] ?? [];
    }

    /** @return array<string,array{type:string,label:string,options?:array,required?:bool}> */
    protected function normalize(array $filters): array
    {
        $out = [];
        foreach ($filters as $k => $v) {
            if (is_int($k)) {
                $out[$v] = ['type' => 'entity', 'label' => self::LABELS[$v]];
            } elseif (isset($v['options'])) {
                $out[$k] = ['type' => 'select', 'label' => $v['label'], 'options' => $v['options']];
            } elseif (! empty($v['text'])) {
                $out[$k] = ['type' => 'text', 'label' => $v['label']];
            } else {
                $out[$k] = ['type' => 'entity', 'label' => $v['label'], 'required' => ! empty($v['required'])];
            }
        }

        return $out;
    }
}
