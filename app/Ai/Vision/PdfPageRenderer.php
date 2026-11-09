<?php

namespace App\Ai\Vision;

use App\Ai\Exceptions\UnreadableInvoice;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

final class PdfPageRenderer
{
    private const RESOLUTION_DPI = 150;

    public function firstPageAsJpeg(string $pdfPath): string
    {
        $directory = sys_get_temp_dir().'/ledgerly-'.Str::uuid();

        File::ensureDirectoryExists($directory);

        try {
            return $this->render($pdfPath, $directory);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function render(string $pdfPath, string $directory): string
    {
        $result = Process::run([
            'pdftoppm',
            '-jpeg',
            '-r',
            (string) self::RESOLUTION_DPI,
            '-f',
            '1',
            '-l',
            '1',
            '-singlefile',
            $pdfPath,
            "{$directory}/page",
        ]);

        if ($result->failed()) {
            throw UnreadableInvoice::pdfRenderingFailed(trim($result->errorOutput()) ?: 'pdftoppm exited with an error');
        }

        return File::get("{$directory}/page.jpg");
    }
}
