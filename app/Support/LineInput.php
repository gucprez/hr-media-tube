<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Limpieza de las filas del editor de líneas (producto + cajas + unidades).
 *
 * Quien pulsa «Agregar» de más deja filas sin tocar; esas filas no deben impedir guardar ni producir
 * errores técnicos. Una fila se descarta SOLO si producto, cajas y unidades están en blanco: una fila con
 * cantidad pero sin producto se conserva para que la validación la señale.
 */
class LineInput
{
    public static function dropEmpty(Request $request, string $field): void
    {
        $rows = $request->input($field);
        if (! is_array($rows)) {
            return;
        }

        $clean = array_values(array_filter($rows, function ($row): bool {
            if (! is_array($row)) {
                return false;
            }
            foreach (['product_id', 'boxes', 'units'] as $key) {
                $value = $row[$key] ?? null;
                if (is_array($value) || ($value !== null && trim((string) $value) !== '')) {
                    return true;
                }
            }

            return false;
        }));

        $request->merge([$field => $clean]);
    }
}
