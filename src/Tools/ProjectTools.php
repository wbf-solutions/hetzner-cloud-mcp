<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\HetznerClient;
use WBFSolutions\HetznerMcp\ServerResolver;

class ProjectTools
{
    private const SERVER_PROP = ['type' => 'string', 'description' => 'Target server name or alias (see your SERVERS config)'];

    public function __construct(
        private Config $config,
        private HetznerClient $client,
        private ServerResolver $resolver,
    ) {}

    public function getTools(): array
    {
        return [
            [
                'name' => 'project_servers_list',
                'description' => 'List all servers in the Hetzner project with managed status.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serversList(),
            ],
            [
                'name' => 'ssh_keys_list',
                'description' => 'List all SSH keys registered in the Hetzner project.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->sshKeysList(),
            ],
            [
                'name' => 'action_status',
                'description' => 'Check the status of an async Hetzner action. Use the action_id returned by other tools.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'action_id' => ['type' => 'integer', 'description' => 'Action ID to check'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['action_id'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->actionStatus($args),
            ],
        ];
    }

    private function serversList(): array
    {
        $managed = $this->resolver->all();
        $idToName = [];
        foreach ($managed as $name => $def) {
            $idToName[$def['id']] = $name;
        }

        $data = $this->client->get('/servers');
        return array_map(fn(array $s) => [
            'id' => $s['id'],
            'name' => $s['name'],
            'status' => $s['status'],
            'public_ipv4' => $s['public_net']['ipv4']['ip'] ?? null,
            'server_type' => $s['server_type']['name'] ?? null,
            'datacenter' => $s['datacenter']['name'] ?? null,
            'managed_as' => $idToName[$s['id']] ?? null,
        ], $data['servers'] ?? []);
    }

    private function sshKeysList(): array
    {
        $data = $this->client->get('/ssh_keys');
        return array_map(fn(array $k) => [
            'id' => $k['id'],
            'name' => $k['name'],
            'fingerprint' => $k['fingerprint'],
            'created' => $k['created'],
        ], $data['ssh_keys'] ?? []);
    }

    private function actionStatus(array $args): array
    {
        $sid = $this->resolver->resolveServerId($args['server'] ?? $this->resolver->defaultServer());
        $data = $this->client->get("/servers/{$sid}/actions/{$args['action_id']}");
        $a = $data['action'] ?? [];
        return [
            'id' => $a['id'] ?? null,
            'command' => $a['command'] ?? null,
            'status' => $a['status'] ?? null,
            'progress' => $a['progress'] ?? null,
            'started' => $a['started'] ?? null,
            'finished' => $a['finished'] ?? null,
            'error' => $a['error'] ?? null,
        ];
    }
}
