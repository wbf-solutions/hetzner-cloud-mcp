<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * SSH Client for a single server.
 *
 * Connects via Ed25519 key authentication using phpseclib3.
 * Implements lazy connection — only connects when the first command is executed.
 */
class SshClient
{
    private string $ip;
    private int $port;
    private string $user;
    private string $keyPath;
    private int $timeout;
    private bool $needsSudo;
    private ?SSH2 $connection = null;

    public function __construct(string $ip, string $user, string $keyPath, int $timeout = 10, int $port = 22)
    {
        $this->ip = $ip;
        $this->port = $port;
        $this->user = $user;
        $this->keyPath = $keyPath;
        $this->timeout = $timeout;
        $this->needsSudo = ($user !== 'root');
    }

    public function exec(string $command): string
    {
        $ssh = $this->getConnection();
        $output = $ssh->exec($this->wrapCommand($command));

        if ($output === false) {
            throw new \RuntimeException("SSH command execution failed: {$command}");
        }

        return $output;
    }

    public function execWithStatus(string $command): array
    {
        $ssh = $this->getConnection();
        $output = $ssh->exec($this->wrapCommand($command) . '; echo "EXIT_CODE:$?"');

        $exitCode = 0;
        if (preg_match('/EXIT_CODE:(\d+)$/', trim($output), $matches)) {
            $exitCode = (int) $matches[1];
            $output = preg_replace('/EXIT_CODE:\d+$/', '', trim($output));
        }

        return [
            'output' => trim($output),
            'exitCode' => $exitCode,
        ];
    }

    public function testConnection(): bool
    {
        try {
            $this->getConnection();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function wrapCommand(string $command): string
    {
        return $this->needsSudo ? "sudo -n {$command}" : $command;
    }

    private function getConnection(): SSH2
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            return $this->connection;
        }

        if (!file_exists($this->keyPath)) {
            throw new \RuntimeException('SSH key not found. Check SSH_KEY_PATH configuration.');
        }

        $key = PublicKeyLoader::load(file_get_contents($this->keyPath));
        $ssh = new SSH2($this->ip, $this->port, $this->timeout);

        if (!$ssh->login($this->user, $key)) {
            throw new \RuntimeException('SSH authentication failed. Check credentials and server connectivity.');
        }

        $this->connection = $ssh;
        return $ssh;
    }

    public function __destruct()
    {
        if ($this->connection !== null) {
            $this->connection->disconnect();
        }
    }
}
