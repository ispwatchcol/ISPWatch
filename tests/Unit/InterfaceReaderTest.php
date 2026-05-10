<?php

namespace Tests\Unit;

use App\Services\MikroTik\InterfaceReader;
use App\Services\MikroTik\MikroTikApiProtocol;
use App\Services\MikroTik\MikroTikConnectionManager;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class InterfaceReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('log', new class
        {
            public function info(...$args): void
            {
            }

            public function error(...$args): void
            {
            }
        });

        Facade::setFacadeApplication($container);
    }

    public function test_it_reads_structured_output_from_core_ssh_fallback(): void
    {
        $reader = $this->makeReaderWithSshOutput(implode("\n", [
            'name=ether1|#|type=ether|#|running=true|#|disabled=false',
            'name=vlan105|#|type=vlan|#|running=true|#|disabled=false',
            'name=bridge LAN|#|type=bridge|#|running=true|#|disabled=false',
            'name=ISPWatch-VPN-CORE|#|type=l2tp|#|running=true|#|disabled=false',
        ]));

        $result = $reader->getRouterInterfaces('172.123.155.254', 'admin', 'secret');

        $this->assertTrue($result['success']);
        $this->assertSame('CORE_SSH_EXEC', $result['method']);
        $this->assertCount(2, $result['interfaces']);
        $this->assertSame('ether1', $result['interfaces'][0]['name']);
        $this->assertSame('vlan105', $result['interfaces'][1]['name']);
    }

    public function test_it_falls_back_to_terse_output_parser(): void
    {
        $reader = $this->makeReaderWithSshOutput(implode("\n", [
            'Flags: X - disabled, R - running',
            '0 R name=ether1 OLT type=ether',
            '1 X name="vlan 104 WAN" type=vlan',
            '2   name=l2tp-out1 type=l2tp-out',
            '3 R name="bridge LAN" type=bridge',
        ]));

        $result = $reader->getRouterInterfaces('172.123.155.254', 'admin', 'secret');

        $this->assertTrue($result['success']);
        $this->assertSame('CORE_SSH_EXEC', $result['method']);
        $this->assertCount(2, $result['interfaces']);
        $this->assertTrue($result['interfaces'][0]['running']);
        $this->assertTrue($result['interfaces'][1]['disabled']);
        $this->assertSame('ether1 OLT', $result['interfaces'][0]['name']);
        $this->assertSame('vlan 104 WAN', $result['interfaces'][1]['name']);
        $this->assertSame('vlan', $result['interfaces'][1]['type']);
    }

    public function test_it_parses_wrapped_ssh_exec_output(): void
    {
        $reader = $this->makeReaderWithSshOutput(
            'exit-code: 0 output: Flags: X - disabled, R - running\n0 R name=ether3 Router type=ether\n1   name=vlan104 type=vlan\n2   name=bridge type=bridge'
        );

        $result = $reader->getRouterInterfaces('172.123.155.254', 'admin', 'secret');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['interfaces']);
        $this->assertSame('ether3 Router', $result['interfaces'][0]['name']);
        $this->assertSame('vlan104', $result['interfaces'][1]['name']);
    }

    public function test_it_surfaces_router_command_errors_from_ssh_exec_output(): void
    {
        $reader = $this->makeReaderWithSshOutput('exit-code: 0 output: no such item\n');

        $result = $reader->getRouterInterfaces('172.123.155.254', 'admin', 'secret');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no such item', $result['message']);
        $this->assertSame([], $result['interfaces']);
    }

    private function makeReaderWithSshOutput(string $output): InterfaceReader
    {
        $manager = new class($output) extends MikroTikConnectionManager
        {
            public function __construct(private string $output)
            {
            }

            public function tryDirectClientConnection(string $clientIp, int $clientPort = 8728): bool
            {
                return false;
            }

            public function executeSsh(string $command): array
            {
                return [
                    'success' => true,
                    'output' => $this->output,
                ];
            }
        };

        return new InterfaceReader($manager, new MikroTikApiProtocol());
    }
}
