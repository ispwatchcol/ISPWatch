<?php

namespace Tests\Unit;

use App\Services\MikroTik\FirewallRulesManager;
use App\Services\MikroTik\MikroTikApiProtocol;
use App\Services\MikroTik\MikroTikConnectionManager;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class FirewallRulesManagerTest extends TestCase
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

            public function warning(...$args): void
            {
            }

            public function error(...$args): void
            {
            }
        });

        Facade::setFacadeApplication($container);
    }

    public function test_it_prefers_output_field_variant_for_v7_routers(): void
    {
        $manager = new class extends MikroTikConnectionManager
        {
            public array $commands = [];

            public function __construct()
            {
            }

            public function connectClientApi(string $clientIp, int $clientPort, string $clientUser, string $clientPass)
            {
                return false;
            }

            public function executeSsh(string $command): array
            {
                $this->commands[] = $command;

                return [
                    'success' => true,
                    'output' => "ISP_BEGIN\n\nISP_END:0\n",
                ];
            }
        };

        $service = new FirewallRulesManager($manager, new MikroTikApiProtocol());
        $result = $service->applyBlockRulesViaCore(
            '172.16.16.254',
            'admin',
            'secret',
            'ether1',
            '10.0.0.10',
            8728,
            'RouterOS v7.15.3'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('CORE_SSH_DIRECT', $result['method']);
        $this->assertNotEmpty($manager->commands);
        $this->assertStringContainsString('($res->"output")', $manager->commands[0]);
    }

    public function test_it_prefers_tostr_variant_for_v6_routers(): void
    {
        $manager = new class extends MikroTikConnectionManager
        {
            public array $commands = [];

            public function __construct()
            {
            }

            public function connectClientApi(string $clientIp, int $clientPort, string $clientUser, string $clientPass)
            {
                return false;
            }

            public function executeSsh(string $command): array
            {
                $this->commands[] = $command;

                return [
                    'success' => true,
                    'output' => "ISP_BEGIN\n\nISP_END:0\n",
                ];
            }
        };

        $service = new FirewallRulesManager($manager, new MikroTikApiProtocol());
        $result = $service->applyBlockRulesViaCore(
            '172.16.16.254',
            'admin',
            'secret',
            'ether1',
            '10.0.0.10',
            8728,
            '6.49.10'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('CORE_SSH_DIRECT', $result['method']);
        $this->assertNotEmpty($manager->commands);
        $this->assertStringContainsString(':set out [:tostr $res]', $manager->commands[0]);
    }
}
