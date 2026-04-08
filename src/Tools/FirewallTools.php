<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\HetznerClient;
use WBFSolutions\HetznerMcp\ServerResolver;

class FirewallTools
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
                'name' => 'firewall_list',
                'description' => 'List all firewalls in the Hetzner project.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->listFirewalls(),
            ],
            [
                'name' => 'firewall_get',
                'description' => 'Get firewall details and rules by firewall ID.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'firewall_id' => ['type' => 'integer', 'description' => 'Firewall ID'],
                    ],
                    'required' => ['firewall_id'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->getFirewall($args),
            ],
            [
                'name' => 'firewall_set_rules',
                'description' => 'Replace all rules on a firewall. DESTRUCTIVE — replaces ALL existing rules. Requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'firewall_id' => ['type' => 'integer', 'description' => 'Firewall ID'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true — this replaces ALL existing rules and could lock you out'],
                        'rules' => [
                            'type' => 'array',
                            'description' => 'Array of firewall rules',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'direction' => ['type' => 'string', 'enum' => ['in', 'out']],
                                    'protocol' => ['type' => 'string', 'enum' => ['tcp', 'udp', 'icmp', 'esp', 'gre']],
                                    'port' => ['type' => 'string', 'description' => 'Port or range (e.g., "80", "8000-9000")'],
                                    'source_ips' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'destination_ips' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'description' => ['type' => 'string'],
                                ],
                                'required' => ['direction', 'protocol'],
                            ],
                        ],
                    ],
                    'required' => ['firewall_id', 'rules', 'confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->setRules($args),
            ],
            [
                'name' => 'firewall_apply_to_server',
                'description' => 'Apply a firewall to a server.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'firewall_id' => ['type' => 'integer', 'description' => 'Firewall ID to apply'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['firewall_id'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->applyToServer($args),
            ],
            [
                'name' => 'firewall_remove_from_server',
                'description' => 'Remove a firewall from a server.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'firewall_id' => ['type' => 'integer', 'description' => 'Firewall ID to remove'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['firewall_id'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->removeFromServer($args),
            ],
        ];
    }

    private function sid(array $args): int
    {
        return $this->resolver->resolveServerId($args['server'] ?? $this->resolver->defaultServer());
    }

    private function listFirewalls(): array
    {
        $data = $this->client->get('/firewalls');
        return array_map(fn(array $fw) => [
            'id' => $fw['id'],
            'name' => $fw['name'],
            'rules_count' => count($fw['rules'] ?? []),
            'applied_to_count' => count($fw['applied_to'] ?? []),
            'created' => $fw['created'],
        ], $data['firewalls'] ?? []);
    }

    private function getFirewall(array $args): array
    {
        $data = $this->client->get("/firewalls/{$args['firewall_id']}");
        $fw = $data['firewall'];
        return [
            'id' => $fw['id'],
            'name' => $fw['name'],
            'rules' => $fw['rules'] ?? [],
            'applied_to' => $fw['applied_to'] ?? [],
            'created' => $fw['created'],
        ];
    }

    private function setRules(array $args): array
    {
        if (empty($args['confirm']) || $args['confirm'] !== true) {
            throw new \InvalidArgumentException('This destructive action requires confirm=true');
        }
        $data = $this->client->post("/firewalls/{$args['firewall_id']}/actions/set_rules", [
            'rules' => $args['rules'],
        ]);
        return [
            'action_id' => $data['actions'][0]['id'] ?? null,
            'action_status' => $data['actions'][0]['status'] ?? null,
        ];
    }

    private function applyToServer(array $args): array
    {
        $id = $this->sid($args);
        $data = $this->client->post("/firewalls/{$args['firewall_id']}/actions/apply_to_resources", [
            'apply_to' => [['type' => 'server', 'server' => ['id' => $id]]],
        ]);
        return [
            'server' => $this->resolver->resolve($args['server'] ?? $this->resolver->defaultServer()),
            'action_id' => $data['actions'][0]['id'] ?? null,
            'action_status' => $data['actions'][0]['status'] ?? null,
        ];
    }

    private function removeFromServer(array $args): array
    {
        $id = $this->sid($args);
        $data = $this->client->post("/firewalls/{$args['firewall_id']}/actions/remove_from_resources", [
            'remove_from' => [['type' => 'server', 'server' => ['id' => $id]]],
        ]);
        return [
            'server' => $this->resolver->resolve($args['server'] ?? $this->resolver->defaultServer()),
            'action_id' => $data['actions'][0]['id'] ?? null,
            'action_status' => $data['actions'][0]['status'] ?? null,
        ];
    }
}
