<?php

namespace Tests\Unit\Ai\Structured;

use App\Ai\Structured\Provenance;
use PHPUnit\Framework\TestCase;

class ProvenanceTest extends TestCase
{
    public function test_missing_numbers_accepts_a_number_printed_with_thousands_separators(): void
    {
        $missing = Provenance::missingNumbers(['total' => 1284.5], ['total'], 'Total due: $1,284.50');

        $this->assertSame([], $missing);
    }

    public function test_missing_numbers_reports_a_number_that_is_not_in_the_source(): void
    {
        $missing = Provenance::missingNumbers(['total' => 1204.5], ['total'], 'Total due: $1,284.50');

        $this->assertSame(['total' => 1204.5], $missing);
    }

    public function test_missing_numbers_skips_fields_that_are_null_or_absent(): void
    {
        $missing = Provenance::missingNumbers(['total' => null], ['total', 'tax'], 'nothing here');

        $this->assertSame([], $missing);
    }
}
