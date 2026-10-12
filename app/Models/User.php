<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use BelongsToCompany, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'company_id', 'branch_id', 'role_id', 'first_name', 'last_name', 'name', 'username', 'email',
        'password', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $user->name = trim($user->first_name.' '.$user->last_name);
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Overrides individuales: granted=true otorga, false deniega. */
    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withPivot('granted');
    }

    /** Filtra por la empresa dada (los usuarios no usan el scope global por ser el modelo de login). */
    public function scopeOfCompany(Builder $q, ?int $companyId): Builder
    {
        return $q->where('company_id', $companyId);
    }

    protected ?array $permissionCache = null;

    /** Conjunto efectivo de claves de permiso (rol + overrides). */
    public function effectivePermissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }
        if ($this->is_super_admin) {
            return $this->permissionCache = \App\Support\PermissionCatalog::keys();
        }

        $keys = $this->role ? $this->role->permissions()->pluck('key')->all() : [];
        foreach ($this->permissionOverrides()->get() as $p) {
            if ($p->pivot->granted) {
                $keys[] = $p->key;
            } else {
                $keys = array_diff($keys, [$p->key]);
            }
        }

        return $this->permissionCache = array_values(array_unique($keys));
    }

    public function hasPermission(string $key): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return in_array($key, $this->effectivePermissions(), true);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }
}
