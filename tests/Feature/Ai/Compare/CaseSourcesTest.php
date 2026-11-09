<?php

namespace Tests\Feature\Ai\Compare;

use App\Ai\Compare\Cases\CaseSource;
use App\Ai\Compare\Cases\RecordedRequestCases;
use App\Ai\Exceptions\UnusableComparison;
use App\Ai\Facades\Ai;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\RetrievedChunk;
use App\Ai\Retrieval\Retriever;
use App\Models\RecordedRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CaseSourcesTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/cases-'.uniqid();

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_ticket_cases_use_the_classifier_prompt_the_structured_schema_and_the_label(): void
    {
        $built = app(CaseSource::class)->build('ticket.classify', null, 0, false, null);

        $this->assertCount(20, $built->cases);
        $case = $built->cases[0];
        $this->assertSame('ticket.classify', $case->feature);
        $this->assertSame('billing', $case->category);
        $this->assertSame('deterministic', $case->request->profile);
        $this->assertSame('ticket_data', $case->request->responseFormat['json_schema']['name'] ?? 'ticket_data');
        $this->assertStringContainsString('You classify', $case->request->messages[0]['content']);
        $this->assertSame(['exact'], $case->scorerNames());
    }

    public function test_a_limit_samples_the_whole_file_not_its_head(): void
    {
        $built = app(CaseSource::class)->build('ticket.classify', null, 4, false, null);

        $this->assertCount(4, $built->cases);
        $this->assertSame(['ticket-1', 'ticket-6', 'ticket-11', 'ticket-16'], array_map(fn ($case): string => $case->id, $built->cases));
    }

    public function test_support_cases_carry_the_real_prompt_the_help_area_as_category_and_a_recall_scorer(): void
    {
        $this->bindRetriever();
        $file = $this->golden([
            ['id' => 1, 'question' => 'What late fee?', 'chunks' => ['late-fees›How the fee is calculated']],
            ['id' => 2, 'question' => 'best pizza', 'chunks' => []],
        ]);

        $built = app(CaseSource::class)->build('support.reply', $file, 0, false, null);

        $this->assertCount(2, $built->cases);
        $this->assertSame('billing', $built->cases[0]->category);
        $this->assertSame('off-topic', $built->cases[1]->category);
        $this->assertSame(['recall'], $built->cases[0]->scorerNames());
        $messages = $built->cases[0]->request->messages;
        $this->assertSame('What late fee?', $messages[array_key_last($messages)]['content']);
        $this->assertSame('assistant', $built->cases[0]->request->profile);
    }

    public function test_a_judge_adds_a_second_scorer_to_support_cases(): void
    {
        $this->bindRetriever();
        Ai::fake();
        $file = $this->golden([['id' => 1, 'question' => 'What late fee?', 'chunks' => ['late-fees›How the fee is calculated']]]);

        $built = app(CaseSource::class)->build('support.reply', $file, 0, false, Ai::chat('fake'));

        $this->assertSame(['recall', 'judge'], $built->cases[0]->scorerNames());
    }

    public function test_a_question_whose_expected_source_was_not_retrieved_is_skipped_and_reported(): void
    {
        $this->bindRetriever();
        $file = $this->golden([['id' => 9, 'question' => 'Something else', 'chunks' => ['api-keys›How do I create a key?']]]);

        $built = app(CaseSource::class)->build('support.reply', $file, 0, false, null);

        $this->assertSame([], $built->cases);
        $this->assertStringContainsString('#9', $built->skipped[0]);
    }

    public function test_an_unknown_feature_and_a_missing_golden_file_are_clear_errors(): void
    {
        try {
            app(CaseSource::class)->build('nope', null, 0, false, null);
            $this->fail('expected an exception');
        } catch (UnusableComparison $exception) {
            $this->assertStringContainsString('Available: support.reply, ticket.classify', $exception->getMessage());
        }

        $this->expectException(UnusableComparison::class);

        app(CaseSource::class)->build('ticket.classify', $this->directory.'/missing.json', 0, false, null);
    }

    public function test_replay_needs_a_judge_and_recorded_requests(): void
    {
        try {
            app(CaseSource::class)->build('support.reply', null, 0, true, null);
            $this->fail('expected an exception');
        } catch (UnusableComparison $exception) {
            $this->assertStringContainsString('--judge', $exception->getMessage());
        }

        Ai::fake();

        $this->expectException(UnusableComparison::class);
        $this->expectExceptionMessage('AI_RECORD_REQUESTS');

        app(CaseSource::class)->build('support.reply', null, 0, true, Ai::chat('fake'));
    }

    public function test_replay_builds_sensitive_cases_and_skips_requests_that_offer_tools(): void
    {
        Ai::fake();
        $this->recorded(['tools' => []]);
        $this->recorded(['tools' => [['type' => 'function', 'function' => ['name' => 'lookup_invoice']]]]);

        $built = app(CaseSource::class)->build('support.reply', null, 0, true, Ai::chat('fake'));

        $this->assertCount(1, $built->cases);
        $this->assertTrue($built->cases[0]->sensitive);
        $this->assertSame(RecordedRequestCases::CATEGORY, $built->cases[0]->category);
        $this->assertSame('assistant', $built->cases[0]->request->profile);
        $this->assertStringContainsString('offer tools', $built->skipped[0]);
    }

    private function recorded(array $overrides): void
    {
        RecordedRequest::create([
            'feature' => 'support.reply',
            'provider' => 'local',
            'request' => [
                'feature' => 'support.reply',
                'messages' => [['role' => 'system', 'content' => 'rules'], ['role' => 'user', 'content' => 'How do I add a logo?']],
                'options' => [],
                'profile' => null,
                ...$overrides,
            ],
        ]);
    }

    private function golden(array $rows): string
    {
        $path = $this->directory.'/golden.jsonl';

        file_put_contents($path, implode("\n", array_map('json_encode', $rows)));

        return $path;
    }

    private function bindRetriever(): void
    {
        $this->app->instance(Retriever::class, new class implements Retriever
        {
            public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
            {
                if ($question === 'best pizza') {
                    return [];
                }

                return [new RetrievedChunk(
                    id: 1,
                    source: 'resources/help/billing/late-fees.md',
                    title: 'Late fees',
                    headingPath: 'Late fees › How the fee is calculated',
                    content: "## How the fee is calculated\n\nThe fee is 1.5%.",
                    helpVersion: 'abc',
                )];
            }
        });
    }
}
