<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\SshClient;
use WBFSolutions\HetznerMcp\SshManager;
use WBFSolutions\HetznerMcp\ServerResolver;

class SshMiscTools
{
    private const BLOCKED_PATTERNS = [
        '/\brm\s+(-[a-zA-Z]*r[a-zA-Z]*\s+|--recursive\s+)/',
        '/\bdd\s+.*of=\/dev\//',
        '/\bmkfs\./',
        '/\b(halt|poweroff|init\s+0)\b/',
        '/>\s*\/dev\/sda/',
        '/\bchmod\s+000\s/',
        '/\bchmod\s+-R\s+000\s/',
        '/\bchown\s+-R\s+.*\s+\/\s*$/',
        '/\biptables\s+-F/',
        '/\biptables\s+-X/',
        '/\bufw\s+disable/',
        '/\bwget\s+.*\|\s*(sh|bash)/',
        '/\bcurl\s+.*\|\s*(sh|bash)/',
        '/\b(shutdown|reboot)\b/',
        '/\bkill\s+-9\s+1\b/',
        '/\bsystemctl\s+disable\s+ssh/',
        '/\bpasswd\s/',
        '/\buserdel\s/',
        '/\bfdisk\s/',
        '/\bparted\s/',
        '/\btruncate\s/',
        '/\bmv\s+\/etc/',
        '/`[^`]+`/',
        '/\$\([^)]+\)/',
        '/\bcat\s+.*>\s*\/etc\//',
        '/\b(visudo|vipw)\b/',
        '/\bchroot\s/',
        '/\bnc\s+-[elp]/',
        '/\b(python|perl|ruby)\s+-e\s/',
    ];

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
                'name' => 'ssh_cron_list',
                'description' => 'List crontab entries on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('crontab -l 2>&1'),
            ],
            [
                'name' => 'ssh_supervisor_status',
                'description' => 'List supervisor program statuses on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('supervisorctl status 2>&1'),
            ],
            [
                'name' => 'ssh_supervisor_restart',
                'description' => 'Restart a supervisor program on a server.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'program' => ['type' => 'string', 'description' => 'Supervisor program name to restart'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['program'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->supervisorRestart($args),
            ],
            [
                'name' => 'ssh_ufw_status',
                'description' => 'Check UFW firewall status on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('ufw status verbose 2>&1'),
            ],
            [
                'name' => 'ssh_exec',
                'description' => 'Run an arbitrary shell command on a server. USE WITH CAUTION. Dangerous commands (rm -rf /, dd, mkfs, halt, poweroff) are blocked.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'command' => ['type' => 'string', 'description' => 'Shell command to execute'],
                        'timeout' => ['type' => 'integer', 'description' => 'Command timeout in seconds (default: 30, max: 120)'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['command'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->exec($args),
            ],
        ];
    }

    private function ssh(array $args): SshClient
    {
        return $this->sshManager->getClient($args['server'] ?? $this->resolver->defaultServer());
    }

    private function supervisorRestart(array $args): string
    {
        $program = $args['program'] ?? '';
        if ($program === '' || !preg_match('/^[a-zA-Z0-9._:\-]+$/', $program)) {
            throw new \InvalidArgumentException("Invalid program name: {$program}");
        }
        return $this->ssh($args)->exec('supervisorctl restart ' . escapeshellarg($program) . ' 2>&1');
    }

    private function exec(array $args): string
    {
        $command = $args['command'] ?? '';
        if ($command === '') {
            throw new \InvalidArgumentException('Command cannot be empty');
        }

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $command)) {
                throw new \InvalidArgumentException('This command has been blocked for safety. Use the Hetzner Cloud API tools for power management.');
            }
        }

        $timeout = min(max((int) ($args['timeout'] ?? 30), 1), 120);

        return $this->ssh($args)->exec("timeout {$timeout} bash -c " . escapeshellarg($command) . ' 2>&1');
    }
}
