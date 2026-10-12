<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Guarda fotos/documentos de empresa en el disco PRIVADO (se sirven con autorización, nunca por URL pública).
 * Las imágenes se re-codifican en el servidor (JPEG, máx. 1600 px, orientación EXIF corregida, sin metadatos)
 * y se genera miniatura. Los PDF se guardan tal cual.
 */
class ImageStore
{
    public const DISK = 'local';
    public const MAX_SIDE = 1600;
    public const THUMB_SIDE = 400;

    /** @return array{path:string,thumb_path:?string,mime:string,size:int,original_name:string} */
    public function store(UploadedFile $file, string $directory): array
    {
        $directory = trim($directory, '/');
        $name = Str::random(32);

        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            $image = $this->load($file);
            $main = $this->encode($this->fit($image, self::MAX_SIDE));
            $thumb = $this->encode($this->fit($image, self::THUMB_SIDE), 78);
            imagedestroy($image);

            Storage::disk(self::DISK)->put("{$directory}/{$name}.jpg", $main);
            Storage::disk(self::DISK)->put("{$directory}/{$name}_t.jpg", $thumb);

            return ['path' => "{$directory}/{$name}.jpg", 'thumb_path' => "{$directory}/{$name}_t.jpg", 'mime' => 'image/jpeg', 'size' => strlen($main), 'original_name' => Str::limit($file->getClientOriginalName(), 200, '')];
        }

        if ($file->getMimeType() !== 'application/pdf') {
            throw new RuntimeException('Tipo de archivo no permitido.');
        }
        $path = $file->storeAs($directory, $name.'.pdf', self::DISK);

        return ['path' => $path, 'thumb_path' => null, 'mime' => 'application/pdf', 'size' => $file->getSize(), 'original_name' => Str::limit($file->getClientOriginalName(), 200, '')];
    }

    public function delete(?string ...$paths): void
    {
        Storage::disk(self::DISK)->delete(array_filter($paths));
    }

    protected function load(UploadedFile $file): \GdImage
    {
        $info = @getimagesize($file->getRealPath());
        if (! $info || $info[0] * $info[1] > 60_000_000) { // anti "image bomb"
            throw new RuntimeException('Imagen no válida o demasiado grande.');
        }
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $image) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }

        // Orientación EXIF (fotos de celular)
        if (($info['mime'] ?? '') === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file->getRealPath());
            $rotate = match ($exif['Orientation'] ?? 1) {
                3 => 180, 6 => -90, 8 => 90, default => 0,
            };
            if ($rotate) {
                $rotated = imagerotate($image, $rotate, 0);
                if ($rotated) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
        }

        return $image;
    }

    protected function fit(\GdImage $src, int $max): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // PNG con transparencia -> fondo blanco
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    protected function encode(\GdImage $img, int $quality = 82): string
    {
        ob_start();
        imagejpeg($img, null, $quality);
        $data = (string) ob_get_clean();
        imagedestroy($img);

        return $data;
    }
}
