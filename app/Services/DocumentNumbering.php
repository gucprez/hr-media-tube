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
        'movement' => 'Movimientos de inventario',
    ];

    public function next(string $key, ?int $companyId = null): string
    {
        $companyId ??= app(\App\Support\CurrentCompany::class)->id();

        return DB::transaction(function () use ($key, $companyId) {
            $seq = DocumentSequence::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('key', $key)->lockForUpdate()->first();

            if (! $seq) {
                $seq = DocumentSequence::withoutGlobalScopes()->create([
                    'company_id' => $companyId, 'key' => $key, 'prefix' => '', 'next_number' => 1, 'padding' => 4,
                ]);
            }

            $number = $seq->next_number;
            $seq->update(['next_number' => $number + 1]);

            return $seq->prefix.str_pad((string) $number, $seq->padding, '0', STR_PAD_LEFT);
        });
    }
}
