<?php

namespace App\Services\Reports;

use Carbon\CarbonInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/** Excel real: celdas numéricas y de fecha con formato, cabecera de empresa y fila de totales. */
class XlsxExporter
{
    /** @param  array<string,string>  $meta  encabezado (Período, filtros...) */
    public function write(string $path, string $company, string $title, array $meta, ReportResult $result): void
    {
        $options = new Options;
        foreach ($result->columns as $i => $c) {
            $options->setColumnWidth(in_array($c['type'], ReportResult::NUMERIC, true) ? 16 : (in_array($c['type'], ['date', 'datetime'], true) ? 18 : 28), $i + 1);
        }
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title), 0, 31));

        $writer->addRow(Row::fromValuesWithStyle([$company], new Style(fontBold: true, fontSize: 14)));
        $writer->addRow(Row::fromValuesWithStyle([$title], new Style(fontBold: true, fontSize: 12)));
        foreach ($meta as $k => $v) {
            $writer->addRow(Row::fromValues([$k.': '.$v]));
        }
        $writer->addRow(Row::fromValues([]));

        $head = new Style(fontBold: true, fontColor: Color::WHITE, backgroundColor: Color::BLUE, cellAlignment: CellAlignment::CENTER, shouldWrapText: true);
        $writer->addRow(Row::fromValuesWithStyle(array_column($result->columns, 'label'), $head));

        $styles = [];
        foreach ($result->columns as $i => $c) {
            $styles[$i] = new Style(format: match ($c['type']) {
                'int' => '#,##0', 'dec1' => '#,##0.0', 'dec2' => '#,##0.00', 'num' => '#,##0.###', 'date' => 'dd/mm/yyyy', 'datetime' => 'dd/mm/yyyy hh:mm', default => null,
            });
        }
        foreach ($result->rows as $row) {
            $values = [];
            foreach ($result->columns as $c) {
                $values[] = $this->cell($row[$c['key']] ?? null, $c['type']);
            }
            $writer->addRow(Row::fromValuesWithStyles($values, $styles));
        }

        if ($result->totals) {
            $bold = [];
            $values = [];
            foreach ($result->columns as $i => $c) {
                $values[] = array_key_exists($c['key'], $result->totals) ? (float) $result->totals[$c['key']] : ($i === 0 ? 'TOTAL' : null);
                $bold[$i] = new Style(fontBold: true, format: $styles[$i]->format);
            }
            $writer->addRow(Row::fromValuesWithStyles($values, $bold));
        }

        $writer->addRow(Row::fromValues([]));
        foreach ($result->summary as $k => $v) {
            $writer->addRow(Row::fromValues([$k, $v]));
        }
        foreach ($result->notes as $n) {
            $writer->addRow(Row::fromValuesWithStyle([$n], new Style(fontItalic: true)));
        }
        $writer->addRow(Row::fromValues(['Generado: '.now()->format('d/m/Y H:i').' · por '.(auth()->user()->name ?? '')]));
        $writer->close();
    }

    protected function cell(mixed $v, string $type): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (in_array($type, ReportResult::NUMERIC, true)) {
            return is_numeric($v) ? (float) $v : null;
        }
        if (in_array($type, ['date', 'datetime'], true)) {
            return $v instanceof CarbonInterface ? $v->toDateTime() : (string) $v;
        }

        return $this->safe((string) $v);
    }

    /** Evita la inyección de fórmulas en Excel (celdas que empiezan por = + - @). */
    protected function safe(string $s): string
    {
        return $s !== '' && in_array($s[0], ['=', '+', '-', '@'], true) ? "'".$s : $s;
    }
}
