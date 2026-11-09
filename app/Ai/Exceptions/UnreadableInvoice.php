<?php

namespace App\Ai\Exceptions;

use RuntimeException;

final class UnreadableInvoice extends RuntimeException
{
    public static function fileMissing(string $path): self
    {
        return new self("No file at {$path}.");
    }

    public static function unsupportedType(string $extension): self
    {
        return new self("Cannot read .{$extension} files; give invoice:read a PDF, JPG, PNG, GIF, or WebP.");
    }

    public static function pdfRenderingFailed(string $reason): self
    {
        return new self("Could not render the PDF to an image with pdftoppm (Poppler): {$reason}");
    }
}
