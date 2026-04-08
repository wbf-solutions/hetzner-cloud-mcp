<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\DnsClient;

/**
 * DNS management via the Hetzner DNS API.
 * DNS is project-wide (not per-server), so no server param is needed.
 */
class DnsTools
{
    public function __construct(
        private Config $config,
        private DnsClient $client,
    ) {}

    public function getTools(): array
    {
        return [
            [
                'name' => 'dns_zones_list',
                'description' => 'List all DNS zones in the Hetzner project.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->zonesList(),
            ],
            [
                'name' => 'dns_zone_get',
                'description' => 'Get details for a specific DNS zone.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'zone_id' => ['type' => 'string', 'description' => 'Zone ID'],
                    ],
                    'required' => ['zone_id'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->zoneGet($args),
            ],
            [
                'name' => 'dns_zone_create',
                'description' => 'Create a new DNS zone.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Domain name (e.g., "example.com")'],
                        'ttl' => ['type' => 'integer', 'description' => 'Default TTL in seconds (default: 10800)'],
                    ],
                    'required' => ['name'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->zoneCreate($args),
            ],
            [
                'name' => 'dns_zone_delete',
                'description' => 'Delete a DNS zone and all its records. DESTRUCTIVE — requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'zone_id' => ['type' => 'string', 'description' => 'Zone ID to delete'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to execute this destructive action'],
                    ],
                    'required' => ['zone_id', 'confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->zoneDelete($args),
            ],
            [
                'name' => 'dns_records_list',
                'description' => 'List DNS records in a zone. Optionally filter by record type.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'zone_id' => ['type' => 'string', 'description' => 'Zone ID'],
                        'type' => ['type' => 'string', 'description' => 'Filter by record type (A, AAAA, CNAME, MX, TXT, NS, SRV, CAA, etc.)'],
                    ],
                    'required' => ['zone_id'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->recordsList($args),
            ],
            [
                'name' => 'dns_record_add',
                'description' => 'Add a DNS record to a zone. Supported types: A, AAAA, CAA, CNAME, DS, HINFO, HTTPS, MX, NS, PTR, RP, SOA, SRV, SVCB, TLSA, TXT.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'zone_id' => ['type' => 'string', 'description' => 'Zone ID'],
                        'name' => ['type' => 'string', 'description' => 'Record name (e.g., "@", "www", "mail")'],
                        'type' => ['type' => 'string', 'description' => 'Record type (A, AAAA, CNAME, MX, TXT, etc.)'],
                        'value' => ['type' => 'string', 'description' => 'Record value (e.g., IP address, domain)'],
                        'ttl' => ['type' => 'integer', 'description' => 'TTL in seconds (default: 3600)'],
                    ],
                    'required' => ['zone_id', 'name', 'type', 'value'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->recordAdd($args),
            ],
            [
                'name' => 'dns_record_update',
                'description' => 'Update an existing DNS record.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'zone_id' => ['type' => 'string', 'description' => 'Zone ID'],
                        'record_id' => ['type' => 'string', 'description' => 'Record ID to update'],
                        'name' => ['type' => 'string', 'description' => 'New record name'],
                        'type' => ['type' => 'string', 'description' => 'Record type'],
                        'value' => ['type' => 'string', 'description' => 'New record value'],
                        'ttl' => ['type' => 'integer', 'description' => 'TTL in seconds'],
                    ],
                    'required' => ['zone_id', 'record_id', 'value'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->recordUpdate($args),
            ],
            [
                'name' => 'dns_record_delete',
                'description' => 'Delete a DNS record. DESTRUCTIVE — requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'zone_id' => ['type' => 'string', 'description' => 'Zone ID'],
                        'record_id' => ['type' => 'string', 'description' => 'Record ID to delete'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to execute this destructive action'],
                    ],
                    'required' => ['zone_id', 'record_id', 'confirm'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true],
                'handler' => fn(array $args) => $this->recordDelete($args),
            ],
        ];
    }

    private function validateId(string $id, string $label): void
    {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $id)) {
            throw new \InvalidArgumentException("Invalid {$label}: must be alphanumeric");
        }
    }

    private function zonesList(): array
    {
        $data = $this->client->get('/zones');
        return array_map(fn(array $z) => [
            'id' => $z['id'],
            'name' => $z['name'],
            'ttl' => $z['ttl'] ?? null,
            'status' => $z['status'] ?? null,
            'records_count' => $z['records_count'] ?? null,
        ], $data['zones'] ?? []);
    }

    private function zoneGet(array $args): array
    {
        $this->validateId($args['zone_id'], 'zone_id');
        $data = $this->client->get("/zones/{$args['zone_id']}");
        return $data['zone'] ?? $data;
    }

    private function zoneCreate(array $args): array
    {
        $data = $this->client->post('/zones', [
            'name' => $args['name'],
            'ttl' => $args['ttl'] ?? 10800,
        ]);
        return $data['zone'] ?? $data;
    }

    private function zoneDelete(array $args): array
    {
        if (empty($args['confirm']) || $args['confirm'] !== true) {
            throw new \InvalidArgumentException('This destructive action requires confirm=true');
        }

        $this->validateId($args['zone_id'], 'zone_id');
        $this->client->delete("/zones/{$args['zone_id']}");
        return ['deleted' => true, 'zone_id' => $args['zone_id']];
    }

    private function recordsList(array $args): array
    {
        $this->validateId($args['zone_id'], 'zone_id');
        $query = ['zone_id' => $args['zone_id']];
        if (!empty($args['type'])) {
            $query['type'] = $args['type'];
        }
        $data = $this->client->get('/records', $query);
        return $data['records'] ?? [];
    }

    private function recordAdd(array $args): array
    {
        $this->validateId($args['zone_id'], 'zone_id');
        $data = $this->client->post('/records', [
            'zone_id' => $args['zone_id'],
            'name' => $args['name'],
            'type' => $args['type'],
            'value' => $args['value'],
            'ttl' => $args['ttl'] ?? 3600,
        ]);
        return $data['record'] ?? $data;
    }

    private function recordUpdate(array $args): array
    {
        $this->validateId($args['zone_id'], 'zone_id');
        $this->validateId($args['record_id'], 'record_id');
        $body = [
            'zone_id' => $args['zone_id'],
            'value' => $args['value'],
        ];
        if (isset($args['name'])) {
            $body['name'] = $args['name'];
        }
        if (isset($args['type'])) {
            $body['type'] = $args['type'];
        }
        if (isset($args['ttl'])) {
            $body['ttl'] = $args['ttl'];
        }

        $data = $this->client->put("/records/{$args['record_id']}", $body);
        return $data['record'] ?? $data;
    }

    private function recordDelete(array $args): array
    {
        if (empty($args['confirm']) || $args['confirm'] !== true) {
            throw new \InvalidArgumentException('This destructive action requires confirm=true');
        }

        $this->validateId($args['zone_id'], 'zone_id');
        $this->validateId($args['record_id'], 'record_id');
        $this->client->delete("/records/{$args['record_id']}");
        return ['deleted' => true, 'record_id' => $args['record_id']];
    }
}
