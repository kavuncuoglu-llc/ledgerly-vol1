<?php

namespace Tests\Unit\Ai\Retrieval;

use App\Ai\Exceptions\InvalidIndexTable;
use App\Ai\Retrieval\RetrievalMode;
use App\Ai\Retrieval\RetrievalSettings;
use PHPUnit\Framework\TestCase;

class RetrievalSettingsTest extends TestCase
{
    public function test_a_table_name_with_sql_in_it_is_refused(): void
    {
        $this->expectException(InvalidIndexTable::class);

        new RetrievalSettings(RetrievalMode::Hybrid, 0.7, 5, 60, 20, table: 'help_chunks; drop table users');
    }

    public function test_with_keeps_the_pool_at_least_as_wide_as_k(): void
    {
        $settings = (new RetrievalSettings(RetrievalMode::Hybrid, 0.7, 5, 60, 20))->with(k: 30);

        $this->assertSame(30, $settings->candidatePool);
    }

    public function test_with_can_point_at_another_table_and_dimension(): void
    {
        $settings = (new RetrievalSettings(RetrievalMode::Hybrid, 0.7, 5, 60, 20))->with(table: 'bench_bge', dimensions: 1024);

        $this->assertSame('bench_bge', $settings->table);
        $this->assertSame(1024, $settings->dimensions);
    }
}
