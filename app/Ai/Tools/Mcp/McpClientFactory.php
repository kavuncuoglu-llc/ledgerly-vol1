<?php

namespace App\Ai\Tools\Mcp;

use App\Ai\Exceptions\UnsupportedMcpTransport;
use Laravel\Mcp\Client;

final class McpClientFactory
{
    private array $clients = [];

    public function make(string $server, array $config): McpClient
    {
        return $this->clients[$server] ??= new LaravelMcpClient($this->connect($server, $config));
    }

    private function connect(string $server, array $config): Client
    {
        $transport = $config['transport'] ?? 'stdio';

        return match ($transport) {
            'stdio' => Client::local(
                $config['command'][0],
                array_slice($config['command'], 1),
                $config['env'] ?? [],
            ),
            'http' => $this->webClient($config),
            default => throw new UnsupportedMcpTransport($server, $transport),
        };
    }

    private function webClient(array $config): Client
    {
        $client = Client::web($config['url']);

        if (isset($config['token'])) {
            $client->withToken($config['token']);
        }

        return $client;
    }
}
