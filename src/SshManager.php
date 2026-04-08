<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

/**
 * Manages a pool of lazy SshClient instances, one per server.
 */
class SshManager
{
    /** @var array<string, SshClient> */
    private array $clients = [];
    private string $keyPath;
    private int $timeout;
    private int $defaultPort;
    private ServerResolver $resolver;

    public function __construct(Config $config, ServerResolver $resolver)
    {
        $this->keyPath = $config->sshKeyPath();
        $this->timeout = $config->sshTimeout();
        $this->defaultPort = $config->sshPort();
        $this->resolver = $resolver;
    }

    /**
     * Get (or create) an SshClient for the given server key/alias.
     */
    public function getClient(string $serverKey): SshClient
    {
        $canonical = $this->resolver->resolve($serverKey);

        if (!isset($this->clients[$canonical])) {
            $server = $this->resolver->get($canonical);
            $this->clients[$canonical] = new SshClient(
                $server['ip'],
                $server['ssh_user'],
                $this->keyPath,
                $this->timeout,
                $server['ssh_port'] ?? $this->defaultPort
            );
        }

        return $this->clients[$canonical];
    }
}
