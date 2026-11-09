<?php

namespace App\Console\Commands;

use App\Ai\Analysis\BoundaryScanner;
use Illuminate\Console\Command;

class AiBoundaries extends Command
{
    protected $signature = 'ai:boundaries';

    protected $description = 'Fail when raw() or a driver class is used outside the provider layer';

    public function handle(): int
    {
        $violations = (new BoundaryScanner(base_path()))->violations();

        if ($violations === []) {
            $this->info('The provider layer boundary holds: no raw() or driver class outside app/Ai/Drivers and app/Ai/Native.');

            return Command::SUCCESS;
        }

        foreach ($violations as $violation) {
            $this->error("{$violation['file']}:{$violation['line']}  {$violation['rule']}  ({$violation['detail']})");
        }

        return Command::FAILURE;
    }
}
