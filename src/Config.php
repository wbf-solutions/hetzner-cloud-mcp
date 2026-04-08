<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

use Dotenv\Dotenv;

class Config
{
    private array $vars;

    public function __construct(string $basePath)
    {
        $dotenv = Dotenv::createImmutable($basePath);
        $dotenv->safeLoad();

        $this->vars = $_ENV;
    }

    public function hetznerToken(): string
    {
        return $this->required('HETZNER_API_TOKEN');
    }

    public function dnsToken(): ?string
    {
        $value = $this->get('HETZNER_DNS_TOKEN');
        return $value !== '' ? $value : null;
    }

    /**
     * Parse dynamic server definitions from environment variables.
     *
     * Expects SERVERS=name1,name2,... and per-server vars:
     * SERVER_{UPPER}_ID, SERVER_{UPPER}_IP, SERVER_{UPPER}_SSH_USER, SERVER_{UPPER}_SSH_PORT, SERVER_{UPPER}_ALIASES
     *
     * @return array<string, array{id: int, ip: string, ssh_user: string, ssh_port: int, aliases: string[]}>
     */
    public function servers(): array
    {
        $serverList = $this->required('SERVERS');
        $names = array_map('trim', explode(',', $serverList));
        $names = array_filter($names, fn(string $n) => $n !== '');

        if (empty($names)) {
            throw new \RuntimeException('SERVERS env var is empty. Define at least one server name.');
        }

        $servers = [];
        foreach ($names as $name) {
            $upper = strtoupper(str_replace('-', '_', $name));

            $id = $this->required("SERVER_{$upper}_ID");
            $ip = $this->required("SERVER_{$upper}_IP");
            $sshUser = $this->get("SERVER_{$upper}_SSH_USER", 'root');
            $sshPortRaw = $this->get("SERVER_{$upper}_SSH_PORT");
            $sshPort = $sshPortRaw !== '' ? (int) $sshPortRaw : $this->sshPort();
            $aliasesRaw = $this->get("SERVER_{$upper}_ALIASES");

            $aliases = [];
            if ($aliasesRaw !== '') {
                $aliases = array_map('trim', explode(',', $aliasesRaw));
                $aliases = array_filter($aliases, fn(string $a) => $a !== '');
            }

            $servers[$name] = [
                'id' => (int) $id,
                'ip' => $ip,
                'ssh_user' => $sshUser,
                'ssh_port' => $sshPort,
                'aliases' => $aliases,
            ];
        }

        return $servers;
    }

    /**
     * Return the default server name, or the first configured server.
     */
    public function defaultServer(): string
    {
        $default = $this->get('DEFAULT_SERVER');
        if ($default !== '') {
            return $default;
        }

        $serverList = $this->required('SERVERS');
        $names = array_map('trim', explode(',', $serverList));
        return $names[0];
    }

    public function sshPort(): int
    {
        return (int) $this->get('SSH_PORT', '22');
    }

    public function sshKeyPath(): string
    {
        return $this->required('SSH_KEY_PATH');
    }

    public function sshTimeout(): int
    {
        return (int) $this->get('SSH_TIMEOUT', '10');
    }

    public function mcpApiKey(): string
    {
        return $this->get('MCP_API_KEY');
    }

    public function instanceName(): string
    {
        return $this->get('MCP_INSTANCE_NAME', 'hetzner-mcp');
    }

    public function rateLimitPerMinute(): int
    {
        return (int) $this->get('RATE_LIMIT_PER_MINUTE', '60');
    }

    public function rateLimitStorageDir(): string
    {
        return $this->get('RATE_LIMIT_STORAGE_DIR', '/tmp/hetzner-mcp-ratelimit');
    }

    public function oauthIntrospectUrl(): string
    {
        return $this->get('OAUTH_INTROSPECT_URL');
    }

    public function oauthClientId(): string
    {
        return $this->get('OAUTH_CLIENT_ID');
    }

    public function oauthClientSecret(): string
    {
        return $this->get('OAUTH_CLIENT_SECRET');
    }

    public function oauthResourceUrl(): string
    {
        return $this->get('OAUTH_RESOURCE_URL');
    }

    public function oauthEnabled(): bool
    {
        return $this->oauthIntrospectUrl() !== '';
    }

    private function required(string $key): string
    {
        $value = $this->vars[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            throw new \RuntimeException("Required environment variable {$key} is not set");
        }
        return (string) $value;
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->vars[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }
}
