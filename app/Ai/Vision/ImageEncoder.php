<?php

namespace App\Ai\Vision;

use App\Ai\Exceptions\UnreadableInvoice;
use Illuminate\Support\Facades\File;
use Intervention\Image\Format;
use Intervention\Image\Laravel\Facades\Image;

final class ImageEncoder
{
    private const MAX_EDGE_PIXELS = 1024;

    private const JPEG_QUALITY = 85;

    private const IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ];

    public function __construct(
        private readonly PdfPageRenderer $pdfPages,
    ) {}

    public function dataUrl(string $path): string
    {
        if (! File::exists($path)) {
            throw UnreadableInvoice::fileMissing($path);
        }

        $jpeg = Image::decodeBinary($this->binaryFor($path))
            ->scaleDown(self::MAX_EDGE_PIXELS, self::MAX_EDGE_PIXELS)
            ->encodeUsingFormat(Format::JPEG, quality: self::JPEG_QUALITY);

        return 'data:image/jpeg;base64,'.base64_encode((string) $jpeg);
    }

    private function binaryFor(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return $this->pdfPages->firstPageAsJpeg($path);
        }

        if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            throw UnreadableInvoice::unsupportedType($extension);
        }

        return File::get($path);
    }
}
