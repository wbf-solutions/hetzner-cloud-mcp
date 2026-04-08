<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\HetznerClient;
use WBFSolutions\HetznerMcp\ServerResolver;

class SnapshotTools
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
                'name' => 'snapshot_create',
                'description' => 'Create a snapshot of a server.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'description' => ['type' => 'string', 'description' => 'Snapshot description'],
                        'server' => self::SERVER_PROP,
                    ],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->create($args),
            ],
            [
                'name' => 'snapshot_list',
                'description' => 'List all snapshots in the project, sorted by creation date (newest first).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->list(),
            ],
            [
                'name' => 'snapshot_delete',
                'description' => 'Delete a snapshot by ID. DESTRUCTIVE — requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'image_id' => ['type' => 'integer', 'description' => 'Snapshot/image ID to delete'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to execute this destructive action'],
                    ],
                    'required' => ['image_id', 'confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->delete($args),
            ],
            [
                'name' => 'backup_enable',
                'description' => 'Enable automatic backups for a server (+20% server cost).',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->backupAction('enable_backup', $args),
            ],
            [
                'name' => 'backup_disable',
                'description' => 'Disable automatic backups for a server. DESTRUCTIVE — requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to execute this destructive action'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->backupDisable($args),
            ],
        ];
    }

    private function sid(array $args): int
    {
        return $this->resolver->resolveServerId($args['server'] ?? $this->resolver->defaultServer());
    }

    private function create(array $args): array
    {
        $id = $this->sid($args);
        $data = $this->client->post("/servers/{$id}/actions/create_image", [
            'type' => 'snapshot',
            'description' => $args['description'] ?? 'MCP snapshot ' . date('Y-m-d H:i:s'),
        ]);

        return [
            'server' => $this->resolver->resolve($args['server'] ?? $this->resolver->defaultServer()),
            'action_id' => $data['action']['id'] ?? null,
            'action_status' => $data['action']['status'] ?? null,
            'image_id' => $data['image']['id'] ?? null,
            'description' => $data['image']['description'] ?? null,
            'created' => $data['image']['created'] ?? null,
        ];
    }

    private function list(): array
    {
        $data = $this->client->get('/images', [
            'type' => 'snapshot',
            'sort' => 'created:desc',
        ]);

        return array_map(fn(array $img) => [
            'id' => $img['id'],
            'description' => $img['description'],
            'status' => $img['status'],
            'created' => $img['created'],
            'image_size' => $img['image_size'],
            'disk_size' => $img['disk_size'],
            'created_from' => $img['created_from']['name'] ?? null,
        ], $data['images'] ?? []);
    }

    private function delete(array $args): array
    {
        $this->requireConfirm($args);
        $this->client->delete("/images/{$args['image_id']}");
        return ['deleted' => true, 'image_id' => $args['image_id']];
    }

    private function backupAction(string $action, array $args): array
    {
        $id = $this->sid($args);
        $data = $this->client->post("/servers/{$id}/actions/{$action}");
        return [
            'server' => $this->resolver->resolve($args['server'] ?? $this->resolver->defaultServer()),
            'action_id' => $data['action']['id'] ?? null,
            'action_status' => $data['action']['status'] ?? null,
        ];
    }

    private function backupDisable(array $args): array
    {
        $this->requireConfirm($args);
        return $this->backupAction('disable_backup', $args);
    }

    private function requireConfirm(array $args): void
    {
        if (empty($args['confirm']) || $args['confirm'] !== true) {
            throw new \InvalidArgumentException('This destructive action requires confirm=true');
        }
    }
}
