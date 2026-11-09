<?php

namespace App\Ai\Health;

use App\Ai\Doctor\BindProbe;
use App\Ai\Doctor\CheckResult;
use App\Ai\Hardware\ByteSize;
use App\Ai\Hardware\Hardware;
use App\Ai\Hardware\HardwareProbe;
use App\Ai\Hardware\HostInspector;

final class ComposeProfileDoctor
{
    public function __construct(
        private readonly HardwareProbe $probe,
        private readonly HostInspector $host,
        private readonly BindProbe $bind,
    ) {}

    public function resolve(string $requested): string
    {
        return (string) (config('ai.compose.aliases', [])[$requested] ?? $requested);
    }

    public function isKnown(string $profile): bool
    {
        return array_key_exists($profile, (array) config('ai.compose.profiles', []));
    }

    public function known(): array
    {
        return array_keys((array) config('ai.compose.profiles', []));
    }

    public function run(string $profile): array
    {
        $settings = (array) config("ai.compose.profiles.{$profile}");

        $hardware = $this->probe->detect();

        return [
            $this->definedInComposeFile($profile),
            $this->dockerCompose(),
            $this->accelerator($profile, (bool) ($settings['nvidia'] ?? false), $hardware),
            ...$this->nvidiaRuntime((bool) ($settings['nvidia'] ?? false)),
            ...$this->localProvider($profile, (string) ($settings['provider'] ?? '')),
            ...$this->portFree($profile, (int) ($settings['port'] ?? 0)),
        ];
    }

    private function definedInComposeFile(string $profile): CheckResult
    {
        $path = (string) config('ai.compose.file');

        $contents = $this->host->read($path);

        if ($contents === '') {
            return CheckResult::fail("Compose file {$path} is missing or unreadable.");
        }

        if (preg_match_all('/^\s*profiles:\s*\[([^\]]*)\]/m', $contents, $matches) < 1) {
            return CheckResult::fail("No service in {$path} declares a profile.");
        }

        $declared = array_map(fn (string $name): string => trim($name, " \t\"'"), explode(',', implode(',', $matches[1])));

        if (! in_array($profile, $declared, true)) {
            return CheckResult::fail("{$path} declares no service in profile {$profile} (it has ".implode(', ', array_unique($declared)).').');
        }

        return CheckResult::pass("profile {$profile} is declared in {$path}");
    }

    private function dockerCompose(): CheckResult
    {
        $version = $this->host->run(['docker', 'compose', 'version', '--short']);

        if ($version === '') {
            return CheckResult::fail('docker compose is not available on this machine (docker compose version printed nothing).');
        }

        return CheckResult::pass("docker compose {$version}");
    }

    private function accelerator(string $profile, bool $needsNvidia, Hardware $hardware): CheckResult
    {
        if (! $needsNvidia) {
            return CheckResult::pass("profile {$profile} needs no particular accelerator; this machine reports {$hardware->label}");
        }

        if (! str_contains($hardware->reachableNote, 'NVIDIA')) {
            return CheckResult::fail("profile {$profile} needs an NVIDIA GPU; this machine reports {$hardware->label} ({$hardware->reachableNote}).");
        }

        return CheckResult::pass("NVIDIA GPU for profile {$profile}: {$hardware->label}, ".ByteSize::format($hardware->reachableBytes).' of VRAM');
    }

    private function nvidiaRuntime(bool $needsNvidia): array
    {
        if (! $needsNvidia) {
            return [];
        }

        $runtimes = $this->host->run(['docker', 'info', '--format', '{{json .Runtimes}}']);

        if (str_contains($runtimes, 'nvidia')) {
            return [CheckResult::pass('docker has the nvidia runtime')];
        }

        return [CheckResult::warn('docker does not list an nvidia runtime: install the NVIDIA Container Toolkit, otherwise the GPU reservation in the profile cannot be satisfied.')];
    }

    private function localProvider(string $profile, string $provider): array
    {
        if ($provider === '') {
            return [];
        }

        $local = (string) config('ai.tiers.small');

        if ($local === $provider) {
            return [CheckResult::pass("the local role (AI_LOCAL_PROVIDER) points at {$provider}, the provider profile {$profile} serves")];
        }

        return [CheckResult::warn("profile {$profile} serves provider {$provider}, but the local role points at {$local}: set AI_LOCAL_PROVIDER to match, or the app will not talk to this server.")];
    }

    private function portFree(string $profile, int $port): array
    {
        if ($port === 0) {
            return [];
        }

        if ($this->bind->accepts('127.0.0.1', $port)) {
            return [CheckResult::warn("127.0.0.1:{$port} already answers: another process holds the port profile {$profile} publishes, or the profile is already up.")];
        }

        return [CheckResult::pass("127.0.0.1:{$port} is free for profile {$profile}")];
    }
}
