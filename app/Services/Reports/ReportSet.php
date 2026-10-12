<?php

namespace App\Services\Reports;

/** Conjunto de reportes de una sección. Cada definición declara filtros y una función que devuelve un ReportResult. */
abstract class ReportSet
{
    abstract public function section(): string;

    /**
     * @return array<int,array{key:string,title:string,description:string,filters?:array,period?:bool,run:callable}>
     */
    abstract public function definitions(): array;

    /** Atajo para declarar un reporte. */
    protected function def(string $key, string $title, string $description, callable $run, array $filters = [], bool $period = true): array
    {
        return compact('key', 'title', 'description', 'run', 'filters', 'period');
    }
}
