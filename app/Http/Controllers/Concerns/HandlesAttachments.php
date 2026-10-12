<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Attachment;
use App\Services\ImageStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Guarda fotos de un campo del formulario como adjuntos PRIVADOS de un registro y permite limpiar si la operación falla. */
trait HandlesAttachments
{
    /** @param  array<int,array<string,mixed>>  $stored  se llena con lo guardado (para poder borrarlo si la transacción falla) */
    protected function attachFiles(Request $request, Model $record, string $field, string $collection, array &$stored, string $folder = 'files'): int
    {
        $n = 0;
        foreach (array_filter((array) $request->file($field)) as $file) {
            $this->attachFile($request, $record, $file, $collection, $stored, $folder);
            $n++;
        }

        return $n;
    }

    protected function attachFile(Request $request, Model $record, \Illuminate\Http\UploadedFile $file, string $collection, array &$stored, string $folder = 'files'): void
    {
        $info = app(ImageStore::class)->store($file, 'companies/'.$record->company_id.'/'.$folder);
        $stored[] = $info;
        Attachment::create([
            'attachable_type' => $record->getMorphClass(), 'attachable_id' => $record->id, 'collection' => $collection, 'disk' => ImageStore::DISK,
            'path' => $info['path'], 'original_name' => $info['original_name'], 'mime' => $info['mime'], 'size' => $info['size'], 'user_id' => $request->user()->id,
        ]);
    }

    protected function cleanupStored(array $stored): void
    {
        foreach ($stored as $info) {
            app(ImageStore::class)->delete($info['path'], $info['thumb_path'] ?? null);
        }
    }

    /** Reglas de validación para un campo de fotos (hasta $max imágenes de 10 MB). */
    protected function photoRule(string $field, int $max = 5): array
    {
        return [$field => ['nullable', 'array', 'max:'.$max], $field.'.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240']];
    }
}
