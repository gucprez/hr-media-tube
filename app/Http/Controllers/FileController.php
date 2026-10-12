<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Entrega de archivos privados. Los modelos usan el alcance de empresa, por lo que un usuario
 * jamás puede abrir el archivo de otra empresa aunque adivine el id.
 */
class FileController extends Controller
{
    public function productImage(Request $request, ProductImage $image)
    {
        abort_unless($request->user()->hasPermission('products.view') || $request->user()->hasPermission('inventory.view'), 403);
        $path = ($request->boolean('thumb') && $image->thumb_path) ? $image->thumb_path : $image->path;

        return $this->serve($path);
    }

    public function attachment(Request $request, Attachment $attachment)
    {
        abort_unless($request->user()->hasPermission('inventory.view'), 403);

        return $this->serve($attachment->path, $attachment->original_name, $attachment->mime);
    }

    protected function serve(string $path, ?string $name = null, ?string $mime = null)
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $name, array_filter([
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }
}
