<?php

namespace Tests\Unit\Casts;

use App\Casts\Vector;
use App\Models\HelpChunk;
use PHPUnit\Framework\TestCase;

class VectorTest extends TestCase
{
    public function test_set_writes_a_pgvector_literal(): void
    {
        $literal = (new Vector)->set(new HelpChunk, 'embedding', [0.5, -1.0, 0.25], []);

        $this->assertSame('[0.5,-1,0.25]', $literal);
    }

    public function test_get_reads_a_pgvector_literal_into_floats(): void
    {
        $vector = (new Vector)->get(new HelpChunk, 'embedding', '[0.5,-1,0.25]', []);

        $this->assertSame([0.5, -1.0, 0.25], $vector);
    }

    public function test_get_returns_an_empty_vector_for_null(): void
    {
        $this->assertSame([], (new Vector)->get(new HelpChunk, 'embedding', null, []));
    }
}
