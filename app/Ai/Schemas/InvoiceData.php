<?php

namespace App\Ai\Schemas;

use App\Ai\Schema\Describe;
use App\Ai\Schema\ItemsOf;
use App\Ai\Structured\ExtractionMeta;
use App\Ai\Structured\Provenance;
use App\Ai\Structured\StructuredData;
use App\Support\Currency;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final readonly class InvoiceData implements StructuredData
{
    private const TOLERANCE = 0.01;

    public function __construct(
        #[Describe('Vendor or supplier name as printed')]
        public ?string $vendor_name,
        #[Describe('Invoice number or reference, as printed')]
        public ?string $invoice_number,
        #[Describe('Invoice date, ISO 8601 (YYYY-MM-DD); null if not stated')]
        public ?string $invoice_date,
        #[Describe('Due date, ISO 8601 (YYYY-MM-DD); null if not stated')]
        public ?string $due_date,
        #[Describe('ISO 4217 currency code')]
        public ?string $currency,
        #[Describe('Each line item')]
        #[ItemsOf(LineItem::class)]
        public array $line_items,
        #[Describe('Subtotal before tax; null if not printed')]
        public ?float $subtotal,
        #[Describe('Total tax; null if not printed')]
        public ?float $tax,
        #[Describe('Grand total as printed')]
        public ?float $total,
        #[Describe('Short reason if the document cannot be read at all; otherwise null')]
        public ?string $error = null,
        public ExtractionMeta $meta = new ExtractionMeta,
    ) {}

    public static function fromArray(array $data, ExtractionMeta $meta = new ExtractionMeta): static
    {
        return new self(
            vendor_name: $data['vendor_name'] ?? null,
            invoice_number: $data['invoice_number'] ?? null,
            invoice_date: $data['invoice_date'] ?? null,
            due_date: $data['due_date'] ?? null,
            currency: $data['currency'] ?? null,
            line_items: array_map(
                fn (array $line): LineItem => LineItem::fromArray($line),
                $data['line_items'] ?? [],
            ),
            subtotal: self::floatOrNull($data['subtotal'] ?? null),
            tax: self::floatOrNull($data['tax'] ?? null),
            total: self::floatOrNull($data['total'] ?? null),
            error: $data['error'] ?? null,
            meta: $meta,
        );
    }

    public static function rules(): array
    {
        return [
            'vendor_name' => ['nullable', 'string', 'max:200'],
            'invoice_number' => ['nullable', 'string', 'max:64'],
            'invoice_date' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(Currency::codes())],
            'line_items' => ['array', 'max:200'],
            'line_items.*.description' => ['required', 'string', 'max:500'],
            'line_items.*.quantity' => ['required', 'numeric', 'min:0'],
            'line_items.*.unit_price' => ['required', 'numeric'],
            'line_items.*.amount' => ['required', 'numeric'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'error' => ['nullable', 'string', 'max:500'],
        ];
    }

    public static function afterRules(string $sourceText = ''): array
    {
        $rules = [
            fn (Validator $validator) => self::checkSubtotalAgainstLines($validator),
            fn (Validator $validator) => self::checkTotalAgainstParts($validator),
        ];

        if ($sourceText === '') {
            return $rules;
        }

        $rules[] = fn (Validator $validator) => self::checkProvenance($validator, $sourceText);

        return $rules;
    }

    private static function checkSubtotalAgainstLines(Validator $validator): void
    {
        $data = $validator->getData();

        if (! is_numeric($data['subtotal'] ?? null)) {
            return;
        }

        $lines = self::lineItemsTotal($data);

        if (abs($lines - $data['subtotal']) <= self::TOLERANCE) {
            return;
        }

        $validator->errors()->add(
            'subtotal',
            sprintf('Line items sum to %s, not %s.', self::money($lines), self::money($data['subtotal'])),
        );
    }

    private static function checkTotalAgainstParts(Validator $validator): void
    {
        $data = $validator->getData();

        if (! is_numeric($data['total'] ?? null)) {
            return;
        }

        if (! self::hasBasisForTotal($data)) {
            return;
        }

        $expected = self::expectedTotal($data);

        if (abs($expected - $data['total']) <= self::TOLERANCE) {
            return;
        }

        $validator->errors()->add(
            'total',
            sprintf('Total %s does not equal %s (%s).', self::money($data['total']), self::money($expected), self::totalBasis($data)),
        );
    }

    private static function checkProvenance(Validator $validator, string $sourceText): void
    {
        $missing = Provenance::missingNumbers($validator->getData(), ['subtotal', 'tax', 'total'], $sourceText);

        foreach ($missing as $field => $value) {
            $validator->errors()->add($field, "{$field} {$value} does not appear in the document.");
        }
    }

    private static function hasBasisForTotal(array $data): bool
    {
        if (is_numeric($data['subtotal'] ?? null)) {
            return true;
        }

        return ($data['line_items'] ?? []) !== [];
    }

    private static function expectedTotal(array $data): float
    {
        $base = is_numeric($data['subtotal'] ?? null)
            ? (float) $data['subtotal']
            : self::lineItemsTotal($data);

        $tax = is_numeric($data['tax'] ?? null)
            ? (float) $data['tax']
            : 0.0;

        return $base + $tax;
    }

    private static function totalBasis(array $data): string
    {
        return is_numeric($data['subtotal'] ?? null)
            ? 'subtotal plus tax'
            : 'the line items plus tax';
    }

    private static function lineItemsTotal(array $data): float
    {
        $amounts = array_map(
            fn (mixed $line): float => is_array($line) && is_numeric($line['amount'] ?? null)
                ? (float) $line['amount']
                : 0.0,
            is_array($data['line_items'] ?? null) ? $data['line_items'] : [],
        );

        return array_sum($amounts);
    }

    private static function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private static function floatOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return (float) $value;
    }
}
