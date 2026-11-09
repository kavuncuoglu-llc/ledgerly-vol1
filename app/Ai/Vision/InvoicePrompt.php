<?php

namespace App\Ai\Vision;

use App\Ai\Exceptions\UnreadableInvoice;

final class InvoicePrompt
{
    public static function system(): string
    {
        return "You extract data from invoice images. Respond only with JSON matching the schema.\nIf a field is not visible, use null. Never guess.";
    }

    public static function extract(): string
    {
        return 'Extract vendor_name, invoice_number, invoice_date, due_date, currency, '
            .'line_items (description, quantity, unit_price, amount), subtotal, tax, total.';
    }

    public static function parse(string $reply): array
    {
        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');

        if ($start === false || $end === false || $end < $start) {
            throw UnreadableInvoice::noJsonInReply();
        }

        $decoded = json_decode(substr($reply, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            throw UnreadableInvoice::noJsonInReply();
        }

        return $decoded;
    }
}
