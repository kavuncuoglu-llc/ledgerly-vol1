<?php

namespace App\Console\Commands;

use App\Ai\Cache\CachePolicy;
use App\Models\CachedAnswer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CachedAnswersReview extends Command
{
    protected $signature = 'cached-answers:review
        {--approve= : Comma-separated ids to mark approved}
        {--reject= : Comma-separated ids to mark rejected}
        {--share : With --approve, make the approved answers visible to every tenant}
        {--limit=50 : How many automatic answers to list}';

    protected $description = 'List automatically cached answers and approve or reject them; only an approved answer can be shared across tenants';

    public function handle(CachePolicy $policy): int
    {
        $this->reject($this->ids('reject'));

        $approved = $this->approve($this->ids('approve'), $policy);

        if ($approved === false) {
            return Command::FAILURE;
        }

        $this->listAutomaticAnswers();

        return Command::SUCCESS;
    }

    private function approve(array $ids, CachePolicy $policy): bool
    {
        if ($ids === []) {
            return true;
        }

        $rows = $this->rows($ids);

        if ($this->option('share') && $this->hasAccountDetail($rows, $policy)) {
            $this->error('Refusing to share: at least one answer or question contains an email, number or invoice reference.');

            return false;
        }

        foreach ($rows as $row) {
            $row->update([
                'status' => CachedAnswer::STATUS_APPROVED,
                'tenant_id' => $this->option('share')
                    ? null
                    : $row->tenant_id,
            ]);
        }

        $this->info($rows->count().' approved'.$this->shareSuffix());

        return true;
    }

    private function shareSuffix(): string
    {
        if ($this->option('share')) {
            return ' and shared across tenants.';
        }

        return '.';
    }

    private function reject(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $count = CachedAnswer::query()
            ->whereIn('id', $ids)
            ->update(['status' => CachedAnswer::STATUS_REJECTED]);

        $this->info("{$count} rejected.");
    }

    private function hasAccountDetail(Collection $rows, CachePolicy $policy): bool
    {
        return $rows->contains(fn (CachedAnswer $row): bool => $policy->containsAccountDetail($row->question)
            || ! $policy->answerIsStorable($row->answer));
    }

    private function rows(array $ids): Collection
    {
        return CachedAnswer::query()
            ->whereIn('id', $ids)
            ->get();
    }

    private function listAutomaticAnswers(): void
    {
        $rows = CachedAnswer::query()
            ->where('status', CachedAnswer::STATUS_AUTO)
            ->orderByDesc('hit_count')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($rows->isEmpty()) {
            $this->line('No automatic answers waiting for review.');

            return;
        }

        $this->table(
            ['id', 'tenant', 'category', 'hits', 'question', 'answer'],
            $rows->map(fn (CachedAnswer $row): array => [
                $row->id,
                $row->tenant_id ?? 'shared',
                $row->category,
                $row->hit_count,
                Str::limit($row->question, 50),
                Str::limit($row->answer, 70),
            ])->all(),
        );
    }

    private function ids(string $option): array
    {
        $given = (string) $this->option($option);

        if ($given === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $given))));
    }
}
