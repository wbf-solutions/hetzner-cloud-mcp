<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

use WBFSolutions\HetznerMcp\Tools\ServerTools;
use WBFSolutions\HetznerMcp\Tools\SnapshotTools;
use WBFSolutions\HetznerMcp\Tools\FirewallTools;
use WBFSolutions\HetznerMcp\Tools\DnsTools;
use WBFSolutions\HetznerMcp\Tools\ProjectTools;
use WBFSolutions\HetznerMcp\Tools\SshServiceTools;
use WBFSolutions\HetznerMcp\Tools\SshSystemTools;
use WBFSolutions\HetznerMcp\Tools\SshNginxTools;
use WBFSolutions\HetznerMcp\Tools\SshLogTools;
use WBFSolutions\HetznerMcp\Tools\SshDatabaseTools;
use WBFSolutions\HetznerMcp\Tools\SshMiscTools;

class ToolRegistry
{
    /** @var array<string, array{definition: array, handler: callable}> */
    private array $tools = [];

    public function __construct(Config $config)
    {
        $hetznerClient = new HetznerClient($config);
        $resolver = new ServerResolver($config);

        $providers = [
            new ServerTools($config, $hetznerClient, $resolver),
            new SnapshotTools($config, $hetznerClient, $resolver),
            new FirewallTools($config, $hetznerClient, $resolver),
            new ProjectTools($config, $hetznerClient, $resolver),
        ];

        // DNS tools: only register if HETZNER_DNS_TOKEN is configured
        if ($config->dnsToken() !== null) {
            $dnsClient = new DnsClient($config);
            $providers[] = new DnsTools($config, $dnsClient);
        }

        // SSH tools: only register if SSH_KEY_PATH is set and the key file exists
        $sshKeyPath = $config->get('SSH_KEY_PATH');
        if ($sshKeyPath !== '' && file_exists($sshKeyPath)) {
            $sshManager = new SshManager($config, $resolver);

            $providers[] = new SshServiceTools($config, $sshManager, $resolver);
            $providers[] = new SshSystemTools($config, $sshManager, $resolver);
            $providers[] = new SshNginxTools($config, $sshManager, $resolver);
            $providers[] = new SshLogTools($config, $sshManager, $resolver);
            $providers[] = new SshDatabaseTools($config, $sshManager, $resolver);
            $providers[] = new SshMiscTools($config, $sshManager, $resolver);
        }

        foreach ($providers as $provider) {
            foreach ($provider->getTools() as $tool) {
                $definition = [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'inputSchema' => $tool['inputSchema'],
                ];
                if (!empty($tool['annotations'])) {
                    $definition['annotations'] = $tool['annotations'];
                }
                $this->tools[$tool['name']] = [
                    'definition' => $definition,
                    'handler' => $tool['handler'],
                ];
            }
        }
    }

    public function listTools(): array
    {
        return array_map(
            fn($tool) => $tool['definition'],
            array_values($this->tools)
        );
    }

    public function callTool(string $name, array $arguments): mixed
    {
        if (!isset($this->tools[$name])) {
            throw new \InvalidArgumentException("Unknown tool: {$name}");
        }

        return ($this->tools[$name]['handler'])($arguments);
    }
}
