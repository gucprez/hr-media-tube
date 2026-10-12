<?php

namespace App\Services\Reports;

use Carbon\CarbonInterface;

/** Resultado tabular de un reporte: columnas tipadas, filas, totales y resumen. Lo consumen la pantalla, el PDF y el Excel. */
class ReportResult
{
    /** Tipos de columna: text, int, num (hasta 3 decimales), dec1, dec2, date, datetime. */
    public const NUMERIC = ['int', 'num', 'dec1', 'dec2'];

    /** @var array<int,array{key:string,label:string,type:string}> */
    public array $columns = [];

    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    /** @var array<string,mixed> */
    public array $totals = [];

    /** @var array<string,string> */
    public array $summary = [];

    /** @var string[] */
    public array $notes = [];

    /**
     * @param  array<int,array{0:string,1:string,2?:string}>  $columns  [clave, etiqueta, tipo]
     * @param  string[]  $sum  columnas numéricas a totalizar
     */
    public static function make(array $columns, iterable $rows, array $sum = []): self
    {
        $r = new self;
        foreach ($columns as $c) {
            $r->columns[] = ['key' => $c[0], 'label' => $c[1], 'type' => $c[2] ?? 'text'];
        }
        foreach ($rows as $row) {
            $r->rows[] = (array) $row;
        }
        foreach ($sum as $key) {
            $r->totals[$key] = round(array_sum(array_map(fn ($row) => (float) ($row[$key] ?? 0), $r->rows)), 3);
        }

        return $r;
    }

    public function summary(array $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    /** Valor legible (pantalla / PDF). */
    public static function format(mixed $v, string $type): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        return match ($type) {
            'int' => number_format((float) $v, 0, '.', ','),
            'dec1' => number_format((float) $v, 1, '.', ','),
            'dec2' => number_format((float) $v, 2, '.', ','),
            'num' => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.') ?: '0',
            'date' => $v instanceof CarbonInterface ? $v->format('d/m/Y') : (string) $v,
            'datetime' => $v instanceof CarbonInterface ? $v->format('d/m/Y H:i') : (string) $v,
            default => (string) $v,
        };
    }
}
