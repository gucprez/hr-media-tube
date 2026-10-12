<?php

namespace App\Services\Inventory;

use App\Models\Product;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * ÚNICO lugar donde se convierte entre cajas y unidades.
 * El inventario se guarda siempre en UNIDAD BASE; esto lo traduce a "5 cajas + 8 rollos" y viceversa.
 */
class QuantityConverter
{
    /** Cajas y unidades -> unidad base. Valida el modo de manejo del producto. */
    public function toBase(Product $product, float|int|string|null $boxes, float|int|string|null $units): float
    {
        $boxes = $this->num($boxes);
        $units = $this->num($units);

        if ($boxes < 0 || $units < 0) {
            throw new InvalidArgumentException('Las cantidades no pueden ser negativas.');
        }
        if ($boxes != floor($boxes)) {
            throw new InvalidArgumentException('Las cajas deben ser un número entero.');
        }
        if ($product->handling_mode === Product::MODE_UNITS && $boxes > 0) {
            throw new InvalidArgumentException("«{$product->name}» se maneja solo por unidades.");
        }
        if ($product->handling_mode === Product::MODE_BOXES && $units > 0) {
            throw new InvalidArgumentException("«{$product->name}» se maneja solo por cajas.");
        }

        return round($boxes * max(1, (int) $product->units_per_box) + $units, 3);
    }

    /** Unidad base -> [cajas, unidades] según el modo de manejo. */
    public function split(Product $product, float|int|string|null $base): array
    {
        $base = round($this->num($base), 3);
        $sign = $base < 0 ? -1 : 1;
        $abs = abs($base);
        $perBox = max(1, (int) $product->units_per_box);

        if ($product->handling_mode === Product::MODE_UNITS || $perBox === 1 && $product->handling_mode !== Product::MODE_BOXES) {
            return [0, $sign * $abs];
        }

        $boxes = (int) floor($abs / $perBox + 1e-9);
        $units = round($abs - $boxes * $perBox, 3);

        return [$sign * $boxes, $sign * $units];
    }

    /** "5 cajas + 8 rollos" · "188 rollos" · "3 cajas" · "0 unidades". */
    public function format(Product $product, float|int|string|null $base): string
    {
        [$boxes, $units] = $this->split($product, $base);
        $unit = $this->unitLabel($product, abs($units));
        $box = $this->boxLabel($product, abs($boxes));

        return match ($product->handling_mode) {
            Product::MODE_UNITS => $this->n($units).' '.$unit,
            Product::MODE_BOXES => $this->n($boxes).' '.$box,
            default => $boxes == 0
                ? $this->n($units).' '.$unit
                : ($units == 0 ? $this->n($boxes).' '.$box : $this->n($boxes).' '.$box.' + '.$this->n($units).' '.$unit),
        };
    }

    /** Total en unidad base con su etiqueta: "188 rollos". */
    public function formatTotal(Product $product, float|int|string|null $base): string
    {
        return $this->n($this->num($base)).' '.$this->unitLabel($product, $this->num($base));
    }

    /** Umbral mínimo del producto en unidad base según su tipo de mínimo (cajas/unidades). */
    public function minimumBase(Product $product): float
    {
        $min = (float) $product->min_quantity;

        return round($product->alert_unit === 'boxes' ? $min * max(1, (int) $product->units_per_box) : $min, 3);
    }

    public function formatMinimum(Product $product): string
    {
        $min = (float) $product->min_quantity;
        if ($product->alert_unit === 'boxes') {
            return $this->n($min).' '.$this->boxLabel($product, $min);
        }

        return $this->n($min).' '.$this->unitLabel($product, $min);
    }

    public function unitLabel(Product $product, float|int $n = 1): string
    {
        $unit = $product->unit;
        $singular = Str::lower($unit?->name ?? 'unidad');
        $plural = Str::lower($unit?->plural ?: $this->pluralize($singular));

        return abs($n) == 1 ? $singular : $plural;
    }

    public function boxLabel(Product $product, float|int $n = 1): string
    {
        $label = Str::lower($product->box_label ?: 'caja');

        return abs($n) == 1 ? $label : $this->pluralize($label);
    }

    public function pluralize(string $word): string
    {
        if (preg_match('/[aeiouáéíóú]$/iu', $word)) {
            return $word.'s';
        }
        if (Str::endsWith($word, 'z')) {
            return Str::beforeLast($word, 'z').'ces';
        }

        return $word.'es';
    }

    protected function n(float|int $v): string
    {
        $v = round((float) $v, 3);

        return rtrim(rtrim(number_format($v, 3, '.', ','), '0'), '.');
    }

    protected function num(float|int|string|null $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (! is_numeric($v)) {
            throw new InvalidArgumentException('Cantidad no válida.');
        }

        return (float) $v;
    }
}
