<?php

namespace Tests\Unit\Ai\Doctor;

use App\Ai\Doctor\SocketBindProbe;
use PHPUnit\Framework\TestCase;

class SocketBindProbeTest extends TestCase
{
    public function test_it_connects_to_a_listening_port_and_not_to_a_closed_one(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($server, false), (int) strrpos((string) stream_socket_get_name($server, false), ':') + 1);

        $probe = new SocketBindProbe;

        $this->assertTrue($probe->accepts('127.0.0.1', $port));

        fclose($server);

        $this->assertFalse($probe->accepts('127.0.0.1', $port));
    }

    public function test_local_addresses_are_ipv4_and_include_loopback(): void
    {
        $addresses = (new SocketBindProbe)->localAddresses();

        $this->assertContains('127.0.0.1', $addresses);

        foreach ($addresses as $address) {
            $this->assertNotFalse(filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
        }
    }

    public function test_an_ip_address_resolves_to_itself(): void
    {
        $this->assertSame('10.1.2.3', (new SocketBindProbe)->resolve('10.1.2.3'));
    }
}
