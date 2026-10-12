<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'attachable_type', 'attachable_id', 'collection', 'disk', 'path', 'original_name', 'mime', 'size', 'user_id'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function url(): string
    {
        return route('files.attachment', $this->id);
    }
}
