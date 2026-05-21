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
        // Only physical ether/sfp ports are valid WAN candidates; vlan, bridge, l2tp are excluded.
        $this->assertCount(1, $result['interfaces']);
        $this->assertSame('ether1', $result['interfaces'][0]['name']);
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
        // Only ether1 OLT survives — vlan/l2tp/bridge are excluded as non-physical WAN candidates.
        $this->assertCount(1, $result['interfaces']);
        $this->assertTrue($result['interfaces'][0]['running']);
        $this->assertSame('ether1 OLT', $result['interfaces'][0]['name']);
        $this->assertSame('ether', $result['interfaces'][0]['type']);
    }

    public function test_it_parses_wrapped_ssh_exec_output(): void
    {
        $reader = $this->makeReaderWithSshOutput(
            'exit-code: 0 output: Flags: X - disabled, R - running\n0 R name=ether3 Router type=ether\n1   name=vlan104 type=vlan\n2   name=bridge type=bridge'
        );

        $result = $reader->getRouterInterfaces('172.123.155.254', 'admin', 'secret');

        $this->assertTrue($result['success']);
        // Only the physical ether port survives the WAN filter; vlan/bridge are excluded.
        $this->assertCount(1, $result['interfaces']);
        $this->assertSame('ether3 Router', $result['interfaces'][0]['name']);
    }

    public function test_it_surfaces_router_command_errors_from_ssh_exec_output(): void
    {
        $reader = $this->makeReaderWithSshOutput('exit-code: 0 output: no such item\n');

        $result = $reader->getRouterInterfaces('172.123.155.254', 'admin', 'secret');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no such item', $result['message']);
        $this->assertSame([], $result['interfaces']);
    }

    public function test_it_parses_envelope_with_isp_markers_and_extracts_interfaces(): void
    {
        // Simulates the new :do/:put envelope output from the CORE script.
        // tostr_envelope variant on RouterOS 7.x would produce something like this.
        $envelope = "ISP_BEGIN\n" .
                    "exit-code=0;output=Flags: X - disabled, R - running\n" .
                    " 0  R name=\"ether1\" type=\"ether\" running=yes disabled=no\n" .
                    "ISP_END:0\n";

        $reader = $this->makeReaderWithSshOutput($envelope);

        $result = $reader->getRouterInterfaces('172.16.16.254', 'admin', 'secret');

        $this->assertTrue($result['success'], 'should parse envelope and extract interface (got: ' . ($result['message'] ?? 'no message') . ')');
        $this->assertSame('CORE_SSH_EXEC', $result['method']);
        $this->assertCount(1, $result['interfaces']);
        $this->assertSame('ether1', $result['interfaces'][0]['name']);
    }

    public function test_it_detects_isp_fail_marker_when_ssh_exec_throws_on_client(): void
    {
        // Simulates the case where /system ssh-exec hits on-error (e.g. client SSH
        // auth fails or client is unreachable). The envelope contains ISP_FAIL.
        $envelope = "ISP_BEGIN\nISP_FAIL\nISP_END:1\n";

        $reader = $this->makeReaderWithSshOutput($envelope);

        $result = $reader->getRouterInterfaces('172.16.16.254', 'admin', 'secret');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no pudo ejecutar ssh-exec', $result['message']);
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
