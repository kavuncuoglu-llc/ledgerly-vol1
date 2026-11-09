<?php

namespace Tests\Feature\Ai\Cache;

use App\Ai\Cache\HelpVersion;
use App\Ai\Cache\SemanticCache;
use App\Ai\Exceptions\CacheScopeRequired;
use App\Enums\TicketCategory;
use App\Models\CachedAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesSemanticCache;
use Tests\TestCase;

class SemanticCacheTest extends TestCase
{
    use FakesSemanticCache;
    use RefreshDatabase;

    private const QUESTION = 'How do I set up recurring invoices?';

    protected function setUp(): void
    {
        parent::setUp();

        $this->disableIndexScans();
    }

    public function test_lookup_returns_the_tenants_own_answer_above_the_threshold_with_its_similarity(): void
    {
        $this->cachedAnswer(['tenant_id' => 7]);
        $this->fakeSemanticStack([self::QUESTION => $this->vectorWithCosine(0, 1, 0.95)]);

        $hit = $this->cache()->lookup(self::QUESTION, TicketCategory::Howto, 7);

        $this->assertTrue($hit->exists);
        $this->assertEqualsWithDelta(0.95, (float) $hit->similarity, 0.001);
    }

    public function test_lookup_misses_below_the_threshold(): void
    {
        $this->cachedAnswer(['tenant_id' => 7]);
        $this->fakeSemanticStack([self::QUESTION => $this->vectorWithCosine(0, 1, 0.91)]);

        $hit = $this->cache()->lookup(self::QUESTION, TicketCategory::Howto, 7);

        $this->assertFalse($hit->exists);
    }

    public function test_lookup_never_returns_another_tenants_automatic_answer(): void
    {
        $this->cachedAnswer(['tenant_id' => 7]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)]);

        $hit = $this->cache()->lookup(self::QUESTION, TicketCategory::Howto, 8);

        $this->assertFalse($hit->exists);
    }

    public function test_lookup_never_returns_another_tenants_approved_answer_either(): void
    {
        $this->cachedAnswer([
            'tenant_id' => 7,
            'status' => CachedAnswer::STATUS_APPROVED,
        ]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)]);

        $hit = $this->cache()->lookup(self::QUESTION, TicketCategory::Howto, 8);

        $this->assertFalse($hit->exists);
    }

    public function test_lookup_returns_a_shared_answer_to_every_tenant_only_once_a_human_approved_it(): void
    {
        $this->cachedAnswer([
            'tenant_id' => null,
            'status' => CachedAnswer::STATUS_APPROVED,
        ]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)]);

        $this->assertTrue($this->cache()->lookup(self::QUESTION, TicketCategory::Howto, 7)->exists);
        $this->assertTrue($this->cache()->lookup(self::QUESTION, TicketCategory::Howto, 8)->exists);
    }

    public function test_lookup_ignores_a_shared_row_that_was_never_approved(): void
    {
        $this->cachedAnswer(['tenant_id' => null]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)]);

        $hit = $this->cache()->lookup(self::QUESTION, TicketCategory::Howto, 7);

        $this->assertFalse($hit->exists);
    }

    public function test_lookup_without_a_tenant_never_reads_the_cache_or_embeds_the_question(): void
    {
        $this->cachedAnswer([
            'tenant_id' => null,
            'status' => CachedAnswer::STATUS_APPROVED,
        ]);
        Http::fake();

        $hit = $this->cache()->lookup(self::QUESTION, TicketCategory::Howto, null);

        $this->assertFalse($hit->exists);
        Http::assertNothingSent();
    }

    public function test_lookup_ignores_rejected_expired_and_outdated_rows(): void
    {
        $this->cachedAnswer([
            'tenant_id' => 7,
            'status' => CachedAnswer::STATUS_REJECTED,
        ]);
        $this->cachedAnswer([
            'tenant_id' => 7,
            'expires_at' => now()->subMinute(),
        ]);
        $this->cachedAnswer([
            'tenant_id' => 7,
            'help_version' => 'an-older-help-center',
        ]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)]);

        $hit = $this->cache()->lookup(self::QUESTION, TicketCategory::Howto, 7);

        $this->assertFalse($hit->exists);
    }

    public function test_lookup_does_not_look_at_all_for_a_category_that_is_not_cacheable(): void
    {
        $this->cachedAnswer(['tenant_id' => 7]);
        Http::fake();

        $hit = $this->cache()->lookup(self::QUESTION, TicketCategory::Billing, 7);

        $this->assertFalse($hit->exists);
        Http::assertNothingSent();
    }

    public function test_lookup_any_cacheable_matches_on_the_category_stored_with_the_row(): void
    {
        $this->cachedAnswer([
            'tenant_id' => 7,
            'category' => 'billing',
        ]);
        $this->cachedAnswer(['tenant_id' => 7]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)]);

        $hit = $this->cache()->lookupAnyCacheable(self::QUESTION, 7);

        $this->assertSame('howto', $hit->category);
    }

    public function test_lookup_picks_the_nearest_of_several_answers(): void
    {
        $this->cachedAnswer([
            'tenant_id' => 7,
            'question' => 'Far question',
            'embedding' => $this->vectorWithCosine(0, 1, 0.5),
        ]);
        $this->cachedAnswer([
            'tenant_id' => 7,
            'question' => 'Near question',
            'embedding' => $this->vectorWithCosine(0, 1, 0.97),
        ]);
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(0)]);

        $hit = $this->cache()->lookup(self::QUESTION, TicketCategory::Howto, 7);

        $this->assertSame('Near question', $hit->question);
    }

    public function test_store_saves_an_automatic_answer_for_the_tenant_with_the_help_version_and_an_expiry(): void
    {
        $this->fakeSemanticStack([self::QUESTION => $this->unitVector(3)]);

        $stored = $this->cache()->store(self::QUESTION, TicketCategory::Howto, 'Open Recurring and save.', 7);

        $this->assertSame(7, $stored->tenant_id);
        $this->assertSame('howto', $stored->category);
        $this->assertSame(CachedAnswer::STATUS_AUTO, $stored->status);
        $this->assertSame(0, $stored->hit_count);
        $this->assertNotNull($stored->expires_at);
        $this->assertSame(app(HelpVersion::class)->current(), $stored->help_version);
        $this->assertSame(768, count(CachedAnswer::query()->sole()->embedding));
        Http::assertSent(fn (Request $request): bool => $request['input'] === 'search_query: '.self::QUESTION);
    }

    public function test_store_refuses_to_save_without_a_tenant_so_nothing_is_ever_shared_automatically(): void
    {
        Http::fake();

        $this->expectException(CacheScopeRequired::class);

        $this->cache()->store(self::QUESTION, TicketCategory::Howto, 'Open Recurring and save.', null);
    }

    public function test_record_hit_increments_the_hit_count(): void
    {
        $row = $this->cachedAnswer(['tenant_id' => 7]);

        $this->cache()->recordHit($row);
        $this->cache()->recordHit($row);

        $this->assertSame(2, $row->fresh()->hit_count);
    }

    private function cache(): SemanticCache
    {
        return app(SemanticCache::class);
    }
}
