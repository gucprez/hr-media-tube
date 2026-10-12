<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InspectionChecklistItem extends Model
{
    use BelongsToCompany;

    public const DEFAULTS = [
        'Luces delanteras', 'Luces traseras', 'Direccionales', 'Luces de freno', 'Puertas', 'Cerraduras', 'Gomas', 'Frenos', 'Espejos',
        'Cristales', 'Limpiavidrios', 'Batería', 'Aceite', 'Refrigerante', 'Bocina', 'Carrocería', 'Sistema eléctrico', 'Otros',
    ];

    protected $fillable = ['company_id', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
