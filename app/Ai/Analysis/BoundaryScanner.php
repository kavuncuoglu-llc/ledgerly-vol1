<?php

namespace App\Ai\Analysis;

use Illuminate\Support\Facades\File;

final class BoundaryScanner
{
    public const RULE_RAW = 'raw() outside app/Ai/Native';

    public const RULE_DRIVER = 'driver class outside app/Ai/Drivers';

    private const DRIVER_NAMESPACE = 'App\\Ai\\Drivers\\';

    private const RAW_ALLOWED = [
        'app/Ai/Native/',
        'app/Ai/Drivers/',
        'app/Ai/Decorators/',
    ];

    private const DRIVER_ALLOWED = [
        'app/Ai/Native/',
        'app/Ai/Drivers/',
        'app/Ai/Testing/',
        'app/Ai/AiManager.php',
        'app/Providers/AiServiceProvider.php',
    ];

    public function __construct(private readonly string $root) {}

    public function violations(): array
    {
        $violations = [];

        foreach (File::allFiles($this->root.'/app') as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

            $violations = [...$violations, ...$this->violationsIn($relative, $file->getContents())];
        }

        return $violations;
    }

    public function violationsIn(string $relativePath, string $source): array
    {
        $tokens = token_get_all($source);

        return [
            ...$this->rawCalls($relativePath, $tokens),
            ...$this->driverReferences($relativePath, $tokens),
        ];
    }

    private function rawCalls(string $path, array $tokens): array
    {
        if ($this->isAllowed($path, self::RAW_ALLOWED)) {
            return [];
        }

        $violations = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] !== T_OBJECT_OPERATOR && $token[0] !== T_NULLSAFE_OBJECT_OPERATOR) {
                continue;
            }

            if (! $this->isCallTo('raw', $tokens, $index + 1)) {
                continue;
            }

            $violations[] = $this->violation($path, $token[2], self::RULE_RAW, '->raw()');
        }

        return $violations;
    }

    private function driverReferences(string $path, array $tokens): array
    {
        if ($this->isAllowed($path, self::DRIVER_ALLOWED)) {
            return [];
        }

        $violations = [];

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            if (! str_starts_with(ltrim($token[1], '\\'), self::DRIVER_NAMESPACE)) {
                continue;
            }

            $violations[] = $this->violation($path, $token[2], self::RULE_DRIVER, ltrim($token[1], '\\'));
        }

        return $violations;
    }

    private function isCallTo(string $method, array $tokens, int $from): bool
    {
        $name = $this->nextSignificant($tokens, $from);

        if ($name === null) {
            return false;
        }

        if (! is_array($tokens[$name]) || $tokens[$name][1] !== $method) {
            return false;
        }

        $open = $this->nextSignificant($tokens, $name + 1);

        return $open !== null && $tokens[$open] === '(';
    }

    private function nextSignificant(array $tokens, int $from): ?int
    {
        for ($index = $from; $index < count($tokens); $index++) {
            if (is_array($tokens[$index]) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $index;
        }

        return null;
    }

    private function isAllowed(string $path, array $allowed): bool
    {
        foreach ($allowed as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function violation(string $path, int $line, string $rule, string $detail): array
    {
        return [
            'file' => $path,
            'line' => $line,
            'rule' => $rule,
            'detail' => $detail,
        ];
    }
}
