<?php

namespace App\Services;

use App\Services\MikroTik\MikroTikConnectionManager;
use App\Services\MikroTik\MikroTikApiProtocol;
use App\Services\MikroTik\PppSecretManager;
use App\Services\MikroTik\PppProfileManager;
use App\Services\MikroTik\SuspensionManager;
use App\Services\MikroTik\QueueManager;
use App\Services\MikroTik\InterfaceReader;
use App\Services\MikroTik\FirewallRulesManager;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

/**
 * MikroTik SSH Service (Facade)
 * 
 * REFACTORED: This service now acts as a facade, delegating to specialized managers.
 * The original monolithic class has been split into:
 * - MikroTikConnectionManager: SSH/API connections
 * - MikroTikApiProtocol: Low-level API protocol handling
 * - PppSecretManager: VPN user management
 * - SuspensionManager: Client suspension/activation
 * - QueueManager: Simple Queue operations
 * - InterfaceReader: Network interface discovery
 * - FirewallRulesManager: Firewall rule management
 * 
 * This facade maintains backward compatibility with existing code.
 */
class MikroTikSshService
{
    private MikroTikConnectionManager $connectionManager;
    private MikroTikApiProtocol $apiProtocol;
    private PppSecretManager $pppManager;
    private PppProfileManager $pppProfileManager;
    private SuspensionManager $suspensionManager;
    private QueueManager $queueManager;
    private InterfaceReader $interfaceReader;
    private FirewallRulesManager $firewallManager;

    public function __construct()
    {
        $this->connectionManager = new MikroTikConnectionManager();
        $this->apiProtocol = $this->connectionManager->getApiProtocol();
        $this->pppManager = new PppSecretManager($this->connectionManager, $this->apiProtocol);
        $this->pppProfileManager = new PppProfileManager($this->connectionManager, $this->apiProtocol);
        $this->suspensionManager = new SuspensionManager($this->connectionManager, $this->apiProtocol);
        $this->queueManager = new QueueManager($this->connectionManager, $this->apiProtocol);
        $this->interfaceReader = new InterfaceReader($this->connectionManager, $this->apiProtocol);
        $this->firewallManager = new FirewallRulesManager($this->connectionManager, $this->apiProtocol);
    }

    // ==================== CONNECTION TESTING ====================

    /**
     * Test both API and SSH connections to MikroTik CORE
     */
    public function testConnection(): array
    {
        return $this->connectionManager->testConnection();
    }

    /**
     * Test API connection to MikroTik CORE
     */
    public function testApiConnection(): array
    {
        return $this->connectionManager->testApiConnection();
    }

    /**
     * Test SSH connection to MikroTik CORE
     */
    public function testSshConnection(?int $timeout = null): array
    {
        return $this->connectionManager->testSshConnection($timeout);
    }

    // ==================== PPP ACTIVE CONNECTIONS ====================

    /**
     * Get PPP active connections (VPN status)
     */
    public function getPppActive(): array
    {
        return $this->pppManager->getPppActive();
    }

    // ==================== VPN CONNECTION CHECK ====================

    /**
     * Check if specific VPN user is connected
     */
    public function isVpnConnected(string $vpnUsername): array
    {
        return $this->pppManager->isVpnConnected($vpnUsername);
    }

    // ==================== PPP SECRET MANAGEMENT ====================

    /**
     * Create or Update PPP secret
     */
    public function ensurePppSecret(string $username, string $password, string $service = 'l2tp', string $profile = 'default', string $comment = 'ISPWatch Auto'): array
    {
        return $this->pppManager->ensurePppSecret($username, $password, $service, $profile, $comment);
    }

    /**
     * Get specific PPP secret details
     */
    public function getPppSecret(string $username): array
    {
        return $this->pppManager->getPppSecret($username);
    }

    /**
     * Create PPP secret for new router (Legacy wrapper)
     */
    public function createPppSecret(string $username, string $password): array
    {
        return $this->pppManager->createPppSecret($username, $password);
    }

    /**
     * Ensure an IP pool exists on the CORE. Creates it if missing.
     */
    public function ensureIpPool(string $poolName, string $ranges): array
    {
        return $this->pppManager->ensureIpPool($poolName, $ranges);
    }

    /**
     * Ensure a tenant-specific PPP profile exists on the CORE. Creates it if missing.
     */
    public function ensurePppProfile(string $profileName, string $localAddress, string $remotePool): array
    {
        return $this->pppManager->ensurePppProfile($profileName, $localAddress, $remotePool);
    }

    /**
     * Create or update a PPPoE /ppp secret on a client router.
     */
    public function ensurePppoeSecretOnRouter(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $username,
        string $password,
        string $profile = 'default',
        string $service = 'pppoe',
        int $clientPort = 8728,
        ?string $remoteAddress = null,
        ?string $localAddress = null
    ): array {
        return $this->pppProfileManager->ensurePppoeSecretOnRouter(
            $clientIp, $clientUser, $clientPass,
            $username, $password, $profile, $service, $clientPort, $remoteAddress, $localAddress
        );
    }

    /**
     * Create or update a PPPoE profile on a client router.
     */
    public function syncPppoeProfileOnRouter(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $profileName,
        string $speedUp,
        string $speedDown,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
        int $clientPort = 8728
    ): array {
        return $this->pppProfileManager->syncPppoeProfile(
            $clientIp,
            $clientUser,
            $clientPass,
            $profileName,
            $speedUp,
            $speedDown,
            $localAddress,
            $remoteAddress,
            $clientPort
        );
    }

    // ==================== SUSPENDED IP MANAGEMENT ====================

    /**
     * Add IP to suspended address-list on CORE
     */
    public function addSuspendedIp(string $ip, string $comment = ''): array
    {
        return $this->suspensionManager->addSuspendedIp($ip, $comment);
    }

    /**
     * Remove IP from suspended address-list on CORE
     */
    public function removeSuspendedIp(string $ip): array
    {
        return $this->suspensionManager->removeSuspendedIp($ip);
    }

    /**
     * Add IP to suspended address-list on CLIENT router via CORE
     */
    public function addSuspendedIpViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $suspendedIp,
        string $customerName,
        int $clientPort = 8728
    ): array {
        return $this->suspensionManager->addSuspendedIpViaCore(
            $clientIp,
            $clientUser,
            $clientPass,
            $suspendedIp,
            $customerName,
            $clientPort
        );
    }

    /**
     * Remove IP from suspended address-list on CLIENT router via CORE
     */
    public function removeSuspendedIpViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $suspendedIp,
        int $clientPort = 8728
    ): array {
        return $this->suspensionManager->removeSuspendedIpViaCore(
            $clientIp,
            $clientUser,
            $clientPass,
            $suspendedIp,
            $clientPort
        );
    }

    // ==================== CLIENT ROUTER VIA CORE ====================

    /**
     * Get interfaces from a client router
     */
    public function getRouterInterfaces(string $clientIp, string $clientUser, string $clientPass, int $clientPort = 8728): array
    {
        return $this->interfaceReader->getRouterInterfaces($clientIp, $clientUser, $clientPass, $clientPort);
    }

    /**
     * Apply block rules to a client router
     */
    public function applyBlockRulesViaCore(string $clientIp, string $clientUser, string $clientPass, string $wanInterface, string $portalIp, int $apiPort = 8728): array
    {
        return $this->firewallManager->applyBlockRulesViaCore(
            $clientIp,
            $clientUser,
            $clientPass,
            $wanInterface,
            $portalIp,
            $apiPort
        );
    }

    /**
     * Get firewall rules from a client router
     */
    public function getFirewallRulesViaCore(string $clientIp, string $clientUser, string $clientPass): array
    {
        return $this->firewallManager->getFirewallRulesViaCore($clientIp, $clientUser, $clientPass);
    }

    // ==================== QUEUE MANAGEMENT ====================

    /**
     * Sync Simple Queue on client router
     */
    public function syncQueueViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $targetIp,
        string $customerName,
        string $customerLastName,
        string $speedUp,
        string $speedDown,
        int $clientPort = 8728
    ): array {
        return $this->queueManager->syncQueueViaCore(
            $clientIp,
            $clientUser,
            $clientPass,
            $targetIp,
            $customerName,
            $customerLastName,
            $speedUp,
            $speedDown,
            $clientPort
        );
    }

    // ==================== SSH CONNECTION ====================

    /**
     * Establish SSH connection
     */
    public function connectSsh(?int $timeout = null): ?SSH2
    {
        return $this->connectionManager->connectSsh($timeout);
    }

    /**
     * Legacy alias for connectSsh
     */
    public function connect(): ?SSH2
    {
        return $this->connectionManager->connect();
    }

    /**
     * Execute command on MikroTik CORE via SSH
     */
    public function execute(string $command): array
    {
        return $this->connectionManager->executeSsh($command);
    }

    // ==================== MANAGER ACCESS ====================

    /**
     * Get the connection manager for advanced use
     */
    public function getConnectionManager(): MikroTikConnectionManager
    {
        return $this->connectionManager;
    }

    /**
     * Get the PPP secret manager for advanced use
     */
    public function getPppManager(): PppSecretManager
    {
        return $this->pppManager;
    }

    /**
     * Get the PPP profile manager for advanced use.
     */
    public function getPppProfileManager(): PppProfileManager
    {
        return $this->pppProfileManager;
    }

    /**
     * Get the suspension manager for advanced use
     */
    public function getSuspensionManager(): SuspensionManager
    {
        return $this->suspensionManager;
    }

    /**
     * Get the queue manager for advanced use
     */
    public function getQueueManager(): QueueManager
    {
        return $this->queueManager;
    }

    /**
     * Get the interface reader for advanced use
     */
    public function getInterfaceReader(): InterfaceReader
    {
        return $this->interfaceReader;
    }

    /**
     * Get the firewall manager for advanced use
     */
    public function getFirewallManager(): FirewallRulesManager
    {
        return $this->firewallManager;
    }
}
