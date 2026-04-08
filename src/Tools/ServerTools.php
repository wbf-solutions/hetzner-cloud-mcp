<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\HetznerClient;
use WBFSolutions\HetznerMcp\ServerResolver;

class ServerTools
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
                'name' => 'server_info',
                'description' => 'Get server details: status, IP, type, datacenter, traffic, rescue mode. Specify which server with the server param.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serverInfo($args),
            ],
            [
                'name' => 'server_metrics',
                'description' => 'Get CPU, disk, or network metrics for a server over a time range.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['cpu', 'disk', 'network'], 'description' => 'Metric type'],
                        'start' => ['type' => 'string', 'description' => 'Start time in ISO 8601 (default: 1 hour ago)'],
                        'end' => ['type' => 'string', 'description' => 'End time in ISO 8601 (default: now)'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['type'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serverMetrics($args),
            ],
            [
                'name' => 'server_power_on',
                'description' => 'Power on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serverAction('poweron', $args),
            ],
            [
                'name' => 'server_power_off',
                'description' => 'Hard power off a server (like pulling the plug). DESTRUCTIVE — requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to execute this destructive action'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->destructiveAction('poweroff', $args),
            ],
            [
                'name' => 'server_shutdown',
                'description' => 'Graceful ACPI shutdown of a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serverAction('shutdown', $args),
            ],
            [
                'name' => 'server_reboot',
                'description' => 'Soft reboot a server via ACPI.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serverAction('reboot', $args),
            ],
            [
                'name' => 'server_reset',
                'description' => 'Hard reset a server (like pressing the reset button). DESTRUCTIVE — requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to execute this destructive action'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->destructiveAction('reset', $args),
            ],
            [
                'name' => 'server_reset_password',
                'description' => 'Reset root password on a server. DESTRUCTIVE — requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to execute this destructive action'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->resetPassword($args),
            ],
            [
                'name' => 'server_rescue_enable',
                'description' => 'Enable rescue mode on a server. Returns root_password.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->rescueEnable($args),
            ],
            [
                'name' => 'server_rescue_disable',
                'description' => 'Disable rescue mode on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->serverAction('disable_rescue', $args),
            ],
            [
                'name' => 'server_rebuild',
                'description' => 'Rebuild a server from an image. VERY DESTRUCTIVE — wipes all data. Requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'image' => ['type' => 'string', 'description' => 'Image name or ID'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to execute this destructive action'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['image', 'confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->rebuild($args),
            ],
            [
                'name' => 'server_change_type',
                'description' => 'Rescale a server to a different plan. DESTRUCTIVE — requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'server_type' => ['type' => 'string', 'description' => 'New server type (e.g., "cx22", "cx32")'],
                        'upgrade_disk' => ['type' => 'boolean', 'description' => 'Whether to upgrade the disk. Default: false'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to execute this destructive action'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['server_type', 'confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->changeType($args),
            ],
        ];
    }

    private function sid(array $args): int
    {
        return $this->resolver->resolveServerId($args['server'] ?? $this->resolver->defaultServer());
    }

    private function serverInfo(array $args): array
    {
        $id = $this->sid($args);
        $data = $this->client->get("/servers/{$id}");
        $s = $data['server'];

        return [
            'server' => $this->resolver->resolve($args['server'] ?? $this->resolver->defaultServer()),
            'id' => $s['id'],
            'name' => $s['name'],
            'status' => $s['status'],
            'public_ipv4' => $s['public_net']['ipv4']['ip'] ?? null,
            'public_ipv6' => $s['public_net']['ipv6']['ip'] ?? null,
            'server_type' => $s['server_type']['name'] ?? null,
            'cores' => $s['server_type']['cores'] ?? null,
            'memory' => $s['server_type']['memory'] ?? null,
            'disk' => $s['server_type']['disk'] ?? null,
            'datacenter' => $s['datacenter']['name'] ?? null,
            'location' => $s['datacenter']['location']['city'] ?? null,
            'image' => $s['image']['description'] ?? $s['image']['name'] ?? null,
            'rescue_enabled' => $s['rescue_enabled'] ?? false,
            'ingoing_traffic' => $s['ingoing_traffic'],
            'outgoing_traffic' => $s['outgoing_traffic'],
            'included_traffic' => $s['included_traffic'],
            'created' => $s['created'],
        ];
    }

    private function serverMetrics(array $args): array
    {
        $id = $this->sid($args);
        $end = $args['end'] ?? date('c');
        $start = $args['start'] ?? date('c', strtotime('-1 hour'));

        return $this->client->get("/servers/{$id}/metrics", [
            'type' => $args['type'],
            'start' => $start,
            'end' => $end,
        ]);
    }

    private function serverAction(string $action, array $args): array
    {
        $id = $this->sid($args);
        $data = $this->client->post("/servers/{$id}/actions/{$action}");
        return $this->formatActionResponse($data, $args);
    }

    private function destructiveAction(string $action, array $args): array
    {
        $this->requireConfirm($args);
        return $this->serverAction($action, $args);
    }

    private function resetPassword(array $args): array
    {
        $this->requireConfirm($args);
        $id = $this->sid($args);
        $data = $this->client->post("/servers/{$id}/actions/reset_password");
        $result = $this->formatActionResponse($data, $args);
        $result['root_password'] = $data['root_password'] ?? null;
        return $result;
    }

    private function rescueEnable(array $args): array
    {
        $id = $this->sid($args);
        $data = $this->client->post("/servers/{$id}/actions/enable_rescue", ['type' => 'linux64']);
        $result = $this->formatActionResponse($data, $args);
        $result['root_password'] = $data['root_password'] ?? null;
        return $result;
    }

    private function rebuild(array $args): array
    {
        $this->requireConfirm($args);
        $id = $this->sid($args);
        $data = $this->client->post("/servers/{$id}/actions/rebuild", ['image' => $args['image']]);
        $result = $this->formatActionResponse($data, $args);
        $result['root_password'] = $data['root_password'] ?? null;
        return $result;
    }

    private function changeType(array $args): array
    {
        $this->requireConfirm($args);
        $id = $this->sid($args);
        $data = $this->client->post("/servers/{$id}/actions/change_type", [
            'server_type' => $args['server_type'],
            'upgrade_disk' => $args['upgrade_disk'] ?? false,
        ]);
        return $this->formatActionResponse($data, $args);
    }

    private function requireConfirm(array $args): void
    {
        if (empty($args['confirm']) || $args['confirm'] !== true) {
            throw new \InvalidArgumentException('This destructive action requires confirm=true');
        }
    }

    private function formatActionResponse(array $data, array $args): array
    {
        $action = $data['action'] ?? [];
        return [
            'server' => $this->resolver->resolve($args['server'] ?? $this->resolver->defaultServer()),
            'action_id' => $action['id'] ?? null,
            'action_status' => $action['status'] ?? null,
            'action_progress' => $action['progress'] ?? null,
            'started' => $action['started'] ?? null,
        ];
    }
}
