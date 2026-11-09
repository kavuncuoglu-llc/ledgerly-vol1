<?php

namespace App\Console\Commands;

use App\Ai\Replies\ReplyLifecycle;
use App\Models\Reply;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RepliesExpirePaused extends Command
{
    protected $signature = 'replies:expire-paused';

    protected $description = 'Expire replies that have waited for an approval longer than ai.replies.paused_expiry_days';

    public function handle(ReplyLifecycle $lifecycle): int
    {
        $expired = 0;

        Reply::query()
            ->withoutGlobalScopes()
            ->pausedBefore($this->cutoff())
            ->each(function (Reply $reply) use ($lifecycle, &$expired): void {
                $lifecycle->expire($reply);

                $expired++;
            });

        $this->info("Expired {$expired} paused replies.");

        return Command::SUCCESS;
    }

    private function cutoff(): Carbon
    {
        return now()->subDays((int) config('ai.replies.paused_expiry_days'));
    }
}
