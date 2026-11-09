<?php

namespace App\Ai\Doctor\Checks;

use App\Ai\Doctor\CheckResult;
use App\Ai\Doctor\DoctorCheck;
use App\Ai\Doctor\DoctorContext;
use App\Ai\Native\OllamaEndpoint;

final class PortExposureCheck implements DoctorCheck
{
    public function run(DoctorContext $context): array
    {
        $url = $context->client->baseUrl();

        $host = OllamaEndpoint::host($url);

        $port = OllamaEndpoint::port($url) ?: OllamaEndpoint::DEFAULT_PORT;

        if ($this->isLoopback($host)) {
            return [$this->localBinding($context, $port)];
        }

        return [$this->remoteHost($context, $host, $port)];
    }

    private function localBinding(DoctorContext $context, int $port): CheckResult
    {
        $public = [];

        $private = [];

        foreach ($context->bind->localAddresses() as $address) {
            if ($this->isLoopback($address)) {
                continue;
            }

            if (! $context->bind->accepts($address, $port)) {
                continue;
            }

            if ($this->isPublic($address)) {
                $public[] = $address;

                continue;
            }

            $private[] = $address;
        }

        if ($public !== []) {
            return CheckResult::fail('port '.$port.' answers on the public address '.implode(', ', $public).': Ollama has no authentication, so anyone who can reach it can run inference and pull models. Bind it to 127.0.0.1 or put a firewall or an authenticating proxy in front.');
        }

        if ($private !== []) {
            return CheckResult::warn('port '.$port.' answers on '.implode(', ', $private).', so the whole local network can use it. Ollama has no authentication; bind it to 127.0.0.1 unless that is intended.');
        }

        return CheckResult::pass("port {$port} not bound on a public interface (loopback only)");
    }

    private function remoteHost(DoctorContext $context, string $host, int $port): CheckResult
    {
        $address = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? $host
            : $context->bind->resolve($host);

        if ($address === '') {
            return CheckResult::warn("could not resolve {$host}, so port exposure could not be judged from here");
        }

        if ($this->isPublic($address)) {
            return CheckResult::fail("{$host}:{$port} resolves to the public address {$address}: Ollama has no authentication. Keep it on a private network or behind an authenticating proxy.");
        }

        return CheckResult::pass("{$host}:{$port} resolves to a private address ({$address}); the app reaches Ollama over a private network. Check from outside that the port is not published.");
    }

    private function isLoopback(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        if ($host === '::1') {
            return true;
        }

        return str_starts_with($host, '127.');
    }

    private function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
