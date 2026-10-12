<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Los roles con company_id NULL son plantillas del sistema (no se asignan
 * directamente: cada empresa recibe copias editables).
 */
class Role extends Model
{
    protected $fillable = ['company_id', 'name', 'slug', 'description', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeForCompany(Builder $q, ?int $companyId): Builder
    {
        return $q->where('company_id', $companyId);
    }
}
