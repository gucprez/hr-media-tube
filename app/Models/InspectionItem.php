<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InspectionItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'vehicle_inspection_id', 'checklist_item_id', 'label', 'status', 'notes'];

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
