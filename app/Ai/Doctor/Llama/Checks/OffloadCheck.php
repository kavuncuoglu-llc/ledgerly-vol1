<?php

namespace App\Ai\Doctor\Llama\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\Llama\LlamaCheck;
use App\Ai\Doctor\Llama\LlamaDoctorContext;

final class OffloadCheck implements LlamaCheck
{
    private const CPU_BACKEND = 'cpu';

    public function run(LlamaDoctorContext $context): array
    {
        if ((string) config('ai.llama.backend') === self::CPU_BACKEND) {
            return [CheckResult::pass('CPU profile: no layers are offloaded by design (-ngl 0)')];
        }

        $declared = strtolower((string) config('ai.llama.gpu_layers'));

        if ($declared === '') {
            return [CheckResult::warn('LLAMA_NGL is not declared for this app, and llama-server does not report how many layers it offloaded (it only logs "offloaded N/N layers to GPU" at start-up). Set LLAMA_NGL in .env to the -ngl the server runs with.')];
        }

        if ($declared === 'all') {
            return [CheckResult::pass('-ngl all declared: every layer is offloaded')];
        }

        if ($declared === 'auto') {
            return [CheckResult::warn('-ngl auto declared: llama-server decides how many layers fit, and does not say how many it chose. Read its start-up log, or declare a number.')];
        }

        if (! ctype_digit($declared)) {
            return [CheckResult::warn("LLAMA_NGL {$declared} is not a number, auto or all.")];
        }

        return [$this->judge((int) $declared, $this->layers())];
    }

    private function judge(int $offloaded, int $layers): CheckResult
    {
        if ($offloaded === 0) {
            return CheckResult::warn('-ngl 0 declared on a GPU profile: every layer runs on the CPU. Set LLAMA_BACKEND=cpu if that is intended.');
        }

        if ($layers === 0) {
            return CheckResult::pass("-ngl {$offloaded} declared (set LLAMA_MODEL_LAYERS to compare it with the model's layer count)");
        }

        if ($offloaded >= $layers) {
            return CheckResult::pass("layers offloaded {$layers}/{$layers} (declared -ngl {$offloaded})");
        }

        return CheckResult::warn("layers offloaded {$offloaded}/{$layers} (declared -ngl {$offloaded}): the rest run on the CPU and decode speed falls with them. Raise LLAMA_NGL if the model fits.");
    }

    private function layers(): int
    {
        return (int) config('ai.llama.model_layers');
    }
}
