<?php

namespace App\Ai\Doctor;

final class SocketBindProbe implements BindProbe
{
    private const CONNECT_TIMEOUT_SECONDS = 1;

    public function localAddresses(): array
    {
        $addresses = [];

        foreach (net_get_interfaces() ?: [] as $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                $address = (string) ($unicast['address'] ?? '');

                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    public function accepts(string $address, int $port): bool
    {
        $socket = @fsockopen($address, $port, $errorCode, $errorMessage, self::CONNECT_TIMEOUT_SECONDS);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    public function resolve(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $resolved = gethostbyname($host);

        return $resolved === $host
            ? ''
            : $resolved;
    }
}
