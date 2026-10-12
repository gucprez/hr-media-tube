<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Registro de auditoría: quién, cuándo, desde dónde, qué y valores antes/después. */
class Audit
{
    public static function log(string $action, ?string $description = null, ?Model $subject = null, ?array $old = null, ?array $new = null): AuditLog
    {
        $user = Auth::user();
        $request = request();

        return AuditLog::create([
            'company_id' => $subject?->company_id ?? app(\App\Support\CurrentCompany::class)->id() ?? $user?->company_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'action' => $action,
            'description' => $description,
            'auditable_type' => $subject ? $subject->getMorphClass() : null,
            'auditable_id' => $subject?->getKey(),
            'old_values' => self::scrub($old),
            'new_values' => self::scrub($new),
        ]);
    }

    /** Registra los cambios de un modelo (usar después de save()). */
    public static function changes(string $action, Model $model, array $original, ?string $description = null): void
    {
        $changed = array_keys($model->getChanges());
        $changed = array_diff($changed, ['updated_at', 'remember_token']);
        $old = array_intersect_key($original, array_flip($changed));
        $new = array_intersect_key($model->getAttributes(), array_flip($changed));
        if (! $changed && $action === 'updated') {
            return;
        }
        self::log($action, $description, $model, $old, $new);
    }

    protected static function scrub(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach (['password', 'remember_token'] as $secret) {
            if (array_key_exists($secret, $values)) {
                $values[$secret] = '[protegido]';
            }
        }

        return $values;
    }
}
