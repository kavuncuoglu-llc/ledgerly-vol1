<?php

namespace App\Console\Commands;

use App\Ai\Profile\MemoryProbeFactory;
use Illuminate\Console\Command;

class AiProfileSample extends Command
{
    private const MICROSECONDS_PER_SECOND = 1_000_000;

    protected $signature = 'ai:profile-sample
        {--provider= : Provider whose server is watched}
        {--interval=1 : Seconds between samples}
        {--count=0 : Stop after this many samples (0 = until killed)}';

    protected $description = 'Print one JSON memory sample per interval; ai:profile runs this next to the model request';

    protected $hidden = true;

    public function handle(MemoryProbeFactory $probes): int
    {
        $probe = $probes->make((string) ($this->option('provider') ?: config('ai.default')));

        $count = (int) $this->option('count');

        $taken = 0;

        while ($count === 0 || $taken < $count) {
            $this->line((string) json_encode($probe->read()->toArray()));

            $taken++;

            usleep((int) round((float) $this->option('interval') * self::MICROSECONDS_PER_SECOND));
        }

        return Command::SUCCESS;
    }
}
