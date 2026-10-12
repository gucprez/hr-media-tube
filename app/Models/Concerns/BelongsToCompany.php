<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aislamiento multiempresa: toda consulta queda limitada a la empresa activa
 * y todo registro nuevo hereda company_id automáticamente.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $id = app(CurrentCompany::class)->id();
            if ($id !== null) {
                $builder->where($builder->getModel()->getTable().'.company_id', $id);
            } elseif (app(CurrentCompany::class)->isEnforced()) {
                // Hay un usuario autenticado sin empresa activa: no ve nada.
                $builder->whereRaw('1 = 0');
            }
        });

        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $model->company_id = app(CurrentCompany::class)->id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
