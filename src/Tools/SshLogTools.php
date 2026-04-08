<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\SshClient;
use WBFSolutions\HetznerMcp\SshManager;
use WBFSolutions\HetznerMcp\ServerResolver;

class SshLogTools
{
    private const SERVER_PROP = ['type' => 'string', 'description' => 'Target server name or alias (see your SERVERS config)'];

    public function __construct(
        private Config $config,
        private SshManager $sshManager,
        private ServerResolver $resolver,
    ) {}

    public function getTools(): array
    {
        $linesSchema = [
            'lines' => ['type' => 'integer', 'description' => 'Number of lines to show (default: 50, max: 500)'],
            'server' => self::SERVER_PROP,
        ];

        return [
            [
                'name' => 'ssh_logs_nginx_error',
                'description' => 'View the last N lines of the nginx error log on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => $linesSchema],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->tailLog('/var/log/nginx/error.log', $args),
            ],
            [
                'name' => 'ssh_logs_nginx_access',
                'description' => 'View the last N lines of the nginx access log on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => $linesSchema],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->tailLog('/var/log/nginx/access.log', $args),
            ],
            [
                'name' => 'ssh_logs_syslog',
                'description' => 'View the last N lines of the system log on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => $linesSchema],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->tailLog('/var/log/syslog', $args),
            ],
            [
                'name' => 'ssh_logs_journal',
                'description' => 'View the systemd journal for a specific unit on a server.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'unit' => ['type' => 'string', 'description' => 'Systemd unit name (e.g., nginx, mysql)'],
                        'lines' => ['type' => 'integer', 'description' => 'Number of lines (default: 50, max: 500)'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['unit'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->journal($args),
            ],
            [
                'name' => 'ssh_logs_supervisor',
                'description' => 'View supervisor program logs on a server.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'program' => ['type' => 'string', 'description' => 'Supervisor program name'],
                        'stream' => ['type' => 'string', 'enum' => ['stdout', 'stderr'], 'description' => 'Log stream (default: stdout)'],
                        'lines' => ['type' => 'integer', 'description' => 'Number of lines (default: 50, max: 500)'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['program'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->supervisorLog($args),
            ],
        ];
    }

    private function ssh(array $args): SshClient
    {
        return $this->sshManager->getClient($args['server'] ?? $this->resolver->defaultServer());
    }

    private function tailLog(string $path, array $args): string
    {
        $lines = $this->clampLines($args);
        return $this->ssh($args)->exec("tail -n {$lines} {$path} 2>&1");
    }

    private function journal(array $args): string
    {
        $unit = $args['unit'] ?? '';
        $this->validateName($unit, 'unit');
        $lines = $this->clampLines($args);

        return $this->ssh($args)->exec("journalctl -u " . escapeshellarg($unit) . " -n {$lines} --no-pager 2>&1");
    }

    private function supervisorLog(array $args): string
    {
        $program = $args['program'] ?? '';
        $this->validateName($program, 'program');
        $stream = ($args['stream'] ?? 'stdout') === 'stderr' ? 'stderr' : 'stdout';
        $lines = $this->clampLines($args);

        return $this->ssh($args)->exec("tail -n {$lines} /var/log/supervisor/" . escapeshellarg("{$program}-{$stream}") . "*.log 2>&1");
    }

    private function clampLines(array $args): int
    {
        return min(max((int) ($args['lines'] ?? 50), 1), 500);
    }

    private function validateName(string $name, string $label): void
    {
        if ($name === '' || !preg_match('/^[a-zA-Z0-9._@\-]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid {$label}: {$name}");
        }
    }
}
