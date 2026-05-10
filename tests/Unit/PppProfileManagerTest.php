<?php

namespace Tests\Unit;

use App\Services\MikroTik\PppProfileManager;
use App\Services\MikroTik\MikroTikApiProtocol;
use App\Services\MikroTik\MikroTikConnectionManager;
use PHPUnit\Framework\TestCase;

class PppProfileManagerTest extends TestCase
{
    public function test_it_builds_rate_limit_from_plan_speeds(): void
    {
        $manager = $this->makeManager();
        $method = new \ReflectionMethod(PppProfileManager::class, 'buildRateLimit');
        $method->setAccessible(true);

        $result = $method->invoke($manager, '5M', '20M');

        $this->assertSame('5M/20M', $result);
    }

    public function test_it_builds_router_command_with_addresses_and_profile_name(): void
    {
        $manager = $this->makeManager();
        $method = new \ReflectionMethod(PppProfileManager::class, 'buildRouterCommand');
        $method->setAccessible(true);

        $command = $method->invoke(
            $manager,
            'Plan PPPoE 20M',
            '5M/20M',
            '10.0.0.1',
            'pool_fibra'
        );

        $this->assertStringContainsString('/ppp profile', $command);
        $this->assertStringContainsString('name="Plan PPPoE 20M"', $command);
        $this->assertStringContainsString('rate-limit="5M/20M"', $command);
        $this->assertStringContainsString('local-address="10.0.0.1"', $command);
        $this->assertStringContainsString('remote-address="pool_fibra"', $command);
    }

    private function makeManager(): PppProfileManager
    {
        $connectionManager = new class extends MikroTikConnectionManager
        {
            public function __construct()
            {
            }
        };

        return new PppProfileManager($connectionManager, new MikroTikApiProtocol());
    }
}
