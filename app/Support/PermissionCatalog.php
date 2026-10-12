<?php

namespace App\Support;

use Illuminate\Support\Str;

class PermissionCatalog
{
    /** @return array<string,array<string,string>> group => [key => label] */
    public static function groups(): array
    {
        return config('permissions.groups');
    }

    /** @return array<string,string> key => label */
    public static function all(): array
    {
        return collect(self::groups())->collapse()->all();
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Expande comodines de una plantilla a claves concretas del catálogo. */
    public static function expand(array $patterns): array
    {
        $keys = self::keys();
        $out = [];
        foreach ($patterns as $p) {
            if ($p === '*') {
                $out = array_merge($out, $keys);
            } elseif ($p === 'view') {
                $out = array_merge($out, array_filter($keys, fn ($k) => Str::endsWith($k, '.view') && ! in_array($k, ['audit.view'], true)));
            } elseif (Str::endsWith($p, '.*')) {
                $prefix = Str::before($p, '.*').'.';
                $out = array_merge($out, array_filter($keys, fn ($k) => Str::startsWith($k, $prefix)));
            } elseif (in_array($p, $keys, true)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }
}
