<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\SshClient;
use WBFSolutions\HetznerMcp\SshManager;
use WBFSolutions\HetznerMcp\ServerResolver;

class SshServiceTools
{
    private const SERVER_PROP = ['type' => 'string', 'description' => 'Target server name or alias (see your SERVERS config)'];

    public function __construct(
        private Config $config,
        private SshManager $sshManager,
        private ServerResolver $resolver,
    ) {}

    public function getTools(): array
    {
        $serviceSchema = [
            'type' => 'object',
            'properties' => [
                'service' => ['type' => 'string', 'description' => 'Service name (e.g., nginx, mysql, redis-server)'],
                'server' => self::SERVER_PROP,
            ],
            'required' => ['service'],
        ];

        return [
            [
                'name' => 'ssh_service_status',
                'description' => 'Check the status of a systemd service on a server.',
                'inputSchema' => $serviceSchema,
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serviceCommand('status', $args),
            ],
            [
                'name' => 'ssh_service_restart',
                'description' => 'Restart a systemd service on a server.',
                'inputSchema' => $serviceSchema,
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serviceCommand('restart', $args),
            ],
            [
                'name' => 'ssh_service_stop',
                'description' => 'Stop a systemd service on a server.',
                'inputSchema' => $serviceSchema,
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serviceCommand('stop', $args),
            ],
            [
                'name' => 'ssh_service_start',
                'description' => 'Start a systemd service on a server.',
                'inputSchema' => $serviceSchema,
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serviceCommand('start', $args),
            ],
            [
                'name' => 'ssh_services_list',
                'description' => 'List all running systemd services on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->servicesList($args),
            ],
        ];
    }

    private function ssh(array $args): SshClient
    {
        return $this->sshManager->getClient($args['server'] ?? $this->resolver->defaultServer());
    }

    private function serviceCommand(string $action, array $args): string
    {
        $service = $args['service'] ?? '';
        $this->validateServiceName($service);

        $flag = $action === 'status' ? ' --no-pager' : '';
        return $this->ssh($args)->exec("systemctl {$action} " . escapeshellarg($service) . "{$flag} 2>&1");
    }

    private function servicesList(array $args): string
    {
        return $this->ssh($args)->exec('systemctl list-units --type=service --state=running --no-pager');
    }

    private function validateServiceName(string $name): void
    {
        if ($name === '' || !preg_match('/^[a-zA-Z0-9._@\-]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid service name: {$name}");
        }
    }
}
