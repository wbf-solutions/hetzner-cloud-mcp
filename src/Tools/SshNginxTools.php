<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\SshClient;
use WBFSolutions\HetznerMcp\SshManager;
use WBFSolutions\HetznerMcp\ServerResolver;

class SshNginxTools
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
                'name' => 'ssh_nginx_test',
                'description' => 'Test nginx configuration syntax on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('nginx -t 2>&1'),
            ],
            [
                'name' => 'ssh_nginx_reload',
                'description' => 'Reload nginx configuration on a server (tests config first).',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('nginx -t 2>&1 && systemctl reload nginx 2>&1'),
            ],
            [
                'name' => 'ssh_nginx_sites_list',
                'description' => 'List enabled nginx sites on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->sitesList($args),
            ],
            [
                'name' => 'ssh_nginx_site_config',
                'description' => 'View the nginx configuration file for a specific site on a server.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'site' => ['type' => 'string', 'description' => 'Site config filename (e.g., "default", "mysite.conf")'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['site'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->siteConfig($args),
            ],
        ];
    }

    private function ssh(array $args): SshClient
    {
        return $this->sshManager->getClient($args['server'] ?? $this->resolver->defaultServer());
    }

    private function sitesList(array $args): string
    {
        $ssh = $this->ssh($args);
        $result = $ssh->execWithStatus('ls -la /etc/nginx/sites-enabled/ 2>/dev/null');
        if ($result['exitCode'] !== 0 || trim($result['output']) === '') {
            $result = $ssh->execWithStatus('ls -la /etc/nginx/conf.d/ 2>/dev/null');
        }
        return $result['output'];
    }

    private function siteConfig(array $args): string
    {
        $site = $args['site'] ?? '';
        if ($site === '' || !preg_match('/^[a-zA-Z0-9._\-]+$/', $site) || str_contains($site, '..')) {
            throw new \InvalidArgumentException('Invalid site name');
        }

        $ssh = $this->ssh($args);
        $result = $ssh->execWithStatus('cat /etc/nginx/sites-enabled/' . escapeshellarg($site) . ' 2>/dev/null');
        if ($result['exitCode'] !== 0) {
            $result = $ssh->execWithStatus('cat /etc/nginx/conf.d/' . escapeshellarg($site) . ' 2>/dev/null');
        }
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException("Site config not found: {$site}");
        }
        return $result['output'];
    }
}
