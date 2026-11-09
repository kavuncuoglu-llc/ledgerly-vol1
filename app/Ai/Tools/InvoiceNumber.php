<?php

namespace App\Ai\Tools;

final class InvoiceNumber
{
    public const PATTERN = '/^\s*#?\s*(INV-?)?\d{1,10}\s*$/i';

    public static function normalize(string $written): string
    {
        $digits = ltrim((string) preg_replace('/\D/', '', $written), '0');

        return 'INV-'.($digits === '' ? '0' : $digits);
    }
}
