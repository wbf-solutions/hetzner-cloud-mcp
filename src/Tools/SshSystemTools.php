<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\SshClient;
use WBFSolutions\HetznerMcp\SshManager;
use WBFSolutions\HetznerMcp\ServerResolver;

class SshSystemTools
{
    private const SERVER_PROP = ['type' => 'string', 'description' => 'Target server name or alias (see your SERVERS config)'];

    public function __construct(
        private Config $config,
        private SshManager $sshManager,
        private ServerResolver $resolver,
    ) {}

    public function getTools(): array
    {
        return [
            [
                'name' => 'ssh_disk_usage',
                'description' => 'Check disk space usage on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('df -h'),
            ],
            [
                'name' => 'ssh_memory_usage',
                'description' => 'Check RAM usage on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('free -h'),
            ],
            [
                'name' => 'ssh_cpu_load',
                'description' => 'Check CPU load and top processes on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('uptime && echo "---" && top -bn1 | head -15'),
            ],
            [
                'name' => 'ssh_process_list',
                'description' => 'List top processes sorted by memory or CPU usage on a server.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'sort_by' => ['type' => 'string', 'enum' => ['memory', 'cpu'], 'description' => 'Sort by memory or cpu (default: memory)'],
                        'count' => ['type' => 'integer', 'description' => 'Number of processes to show (default: 20, max: 50)'],
                        'server' => self::SERVER_PROP,
                    ],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->processList($args),
            ],
            [
                'name' => 'ssh_uptime',
                'description' => 'Get the uptime of a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('uptime'),
            ],
        ];
    }

    private function ssh(array $args): SshClient
    {
        return $this->sshManager->getClient($args['server'] ?? $this->resolver->defaultServer());
    }

    private function processList(array $args): string
    {
        $sortBy = ($args['sort_by'] ?? 'memory') === 'cpu' ? '%cpu' : '%mem';
        $count = min(max((int) ($args['count'] ?? 20), 1), 50);

        return $this->ssh($args)->exec("ps aux --sort=-{$sortBy} | head -" . ($count + 1));
    }
}
