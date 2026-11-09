<?php

namespace App\Ai\Cache;

use Illuminate\Support\Facades\File;
use SplFileInfo;

final class HelpVersion
{
    public function __construct(private readonly string $directory) {}

    public function current(): string
    {
        $fingerprints = collect(File::allFiles($this->directory))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(fn (SplFileInfo $file): string => $file->getPathname().':'.md5_file($file->getPathname()))
            ->sort()
            ->implode("\n");

        return substr(sha1($fingerprints), 0, 12);
    }
}
