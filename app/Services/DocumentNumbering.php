<?php

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/** Numeración correlativa por empresa y tipo de documento (segura ante concurrencia). */
class DocumentNumbering
{
    public const KEYS = [
        'container' => 'Furgones',
        'ticket_materials' => 'Tickets de materiales',
        'ticket_fuel' => 'Tickets de gasoil',
        'fuel_receipt' => 'Recepciones de gasoil',
        'entry' => 'Entradas de almacén',
        'exit' => 'Salidas de almacén',
        'adjustment' => 'Ajustes de inventario',
        'container_move' => 'Consumos de furgón (inventario)',
        'maintenance_move' => 'Salidas por mantenimiento',
        'return' => 'Devoluciones al almacén',
        'void' => 'Anulaciones de inventario',
    ];

    /** Prefijo y relleno por defecto de cada documento. */
    public const DEFAULTS = [
        'entry' => ['prefix' => 'ENT-', 'padding' => 6],
        'exit' => ['prefix' => 'SAL-', 'padding' => 6],
        'adjustment' => ['prefix' => 'AJU-', 'padding' => 6],
        'container_move' => ['prefix' => 'FUR-', 'padding' => 6],
        'maintenance_move' => ['prefix' => 'MNT-', 'padding' => 6],
        'return' => ['prefix' => 'DEV-', 'padding' => 6],
        'void' => ['prefix' => 'ANU-', 'padding' => 6],
        'fuel_receipt' => ['prefix' => 'REC-', 'padding' => 4],
    ];

    public static function defaults(string $key): array
    {
        return self::DEFAULTS[$key] ?? ['prefix' => '', 'padding' => 4];
    }

    public function next(string $key, ?int $companyId = null): string
    {
        $companyId ??= app(\App\Support\CurrentCompany::class)->id();

        return DB::transaction(function () use ($key, $companyId) {
            $seq = DocumentSequence::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('key', $key)->lockForUpdate()->first();

            if (! $seq) {
                $seq = DocumentSequence::withoutGlobalScopes()->create(
                    ['company_id' => $companyId, 'key' => $key, 'next_number' => 1] + self::defaults($key)
                );
            }

            $number = $seq->next_number;
            $seq->update(['next_number' => $number + 1]);

            return $seq->prefix.str_pad((string) $number, $seq->padding, '0', STR_PAD_LEFT);
        });
    }
}
