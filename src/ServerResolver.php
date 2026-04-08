<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

class ServerResolver
{
    private array $servers;
    private array $aliases = [];
    private string $defaultServer;

    public function __construct(Config $config)
    {
        $this->servers = $config->servers();
        $this->defaultServer = $config->defaultServer();

        foreach ($this->servers as $name => $def) {
            $this->aliases[strtolower($name)] = $name;

            foreach ($def['aliases'] ?? [] as $alias) {
                $alias = strtolower(trim($alias));
                if ($alias !== '') {
                    $this->aliases[$alias] = $name;
                }
            }
        }
    }

    /**
     * Resolve a user-provided server name or alias to a canonical server key.
     */
    public function resolve(string $input): string
    {
        if (isset($this->servers[$input])) {
            return $input;
        }

        $normalized = strtolower(trim($input));
        if (isset($this->aliases[$normalized])) {
            return $this->aliases[$normalized];
        }

        throw new \InvalidArgumentException(
            "Unknown server: '{$input}'. Use tools/list to see available servers."
        );
    }

    /**
     * Get a server definition by canonical key or alias.
     *
     * @return array{id: int, ip: string, ssh_user: string, aliases: string[]}
     */
    public function get(string $key): array
    {
        $canonical = $this->resolve($key);
        return $this->servers[$canonical];
    }

    /**
     * Resolve a user input to the Hetzner server ID.
     */
    public function resolveServerId(string $input): int
    {
        return $this->get($input)['id'];
    }

    /**
     * Get all server definitions.
     *
     * @return array<string, array{id: int, ip: string, ssh_user: string, aliases: string[]}>
     */
    public function all(): array
    {
        return $this->servers;
    }

    /**
     * Get canonical server names.
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->servers);
    }

    /**
     * Get the default server name.
     */
    public function defaultServer(): string
    {
        return $this->defaultServer;
    }
}
