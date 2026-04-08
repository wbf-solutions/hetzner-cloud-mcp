<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp\Tools;

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\SshClient;
use WBFSolutions\HetznerMcp\SshManager;
use WBFSolutions\HetznerMcp\ServerResolver;

class SshDatabaseTools
{
    private const WRITE_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'REPLACE', 'GRANT', 'REVOKE', 'RENAME', 'LOAD', 'IMPORT', 'CALL',
        'EXECUTE', 'EXEC', 'LOAD_FILE', 'INTO OUTFILE', 'INTO DUMPFILE',
    ];

    private const ALLOWED_FIRST_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

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
                'name' => 'ssh_mysql_databases',
                'description' => 'List MySQL databases on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('mysql -e "SHOW DATABASES;" 2>&1'),
            ],
            [
                'name' => 'ssh_mysql_processlist',
                'description' => 'Show active MySQL processes on a server.',
                'inputSchema' => ['type' => 'object', 'properties' => ['server' => self::SERVER_PROP]],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->ssh($args)->exec('mysql -e "SHOW PROCESSLIST;" 2>&1'),
            ],
            [
                'name' => 'ssh_mysql_query',
                'description' => 'Run a read-only MySQL query on a server. Only SELECT, SHOW, DESCRIBE, and EXPLAIN queries are allowed.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'SQL query (SELECT, SHOW, DESCRIBE, EXPLAIN only)'],
                        'database' => ['type' => 'string', 'description' => 'Database name to query'],
                        'server' => self::SERVER_PROP,
                    ],
                    'required' => ['query', 'database'],
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false],
                'handler' => fn(array $args) => $this->runQuery($args),
            ],
        ];
    }

    private function ssh(array $args): SshClient
    {
        return $this->sshManager->getClient($args['server'] ?? $this->resolver->defaultServer());
    }

    private function runQuery(array $args): string
    {
        $query = trim($args['query'] ?? '');
        $database = $args['database'] ?? '';

        if ($database === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $database)) {
            throw new \InvalidArgumentException("Invalid database name: {$database}");
        }

        $this->validateReadOnly($query);

        return $this->ssh($args)->exec('mysql ' . escapeshellarg($database) . ' -e ' . escapeshellarg($query) . ' 2>&1');
    }

    private function validateReadOnly(string $query): void
    {
        if ($query === '') {
            throw new \InvalidArgumentException('Query cannot be empty');
        }

        $cleaned = preg_replace('/--.*$/m', '', $query);
        $cleaned = preg_replace('/\/\*.*?\*\//s', '', $cleaned);
        $cleaned = trim($cleaned);

        $noStrings = preg_replace("/'[^']*'/", '', $cleaned);
        $noStrings = preg_replace('/"[^"]*"/', '', $noStrings);
        if (substr_count($noStrings, ';') > 1) {
            throw new \InvalidArgumentException('Multi-statement queries are not allowed');
        }

        if (!preg_match('/^\s*(\w+)/i', $cleaned, $matches)) {
            throw new \InvalidArgumentException('Cannot determine query type');
        }
        $firstKeyword = strtoupper($matches[1]);
        if (!in_array($firstKeyword, self::ALLOWED_FIRST_KEYWORDS, true)) {
            throw new \InvalidArgumentException("Only SELECT, SHOW, DESCRIBE, and EXPLAIN queries are allowed. Got: {$firstKeyword}");
        }

        foreach (self::WRITE_KEYWORDS as $keyword) {
            if (preg_match('/\b' . $keyword . '\b/i', $cleaned)) {
                throw new \InvalidArgumentException("Write operation '{$keyword}' detected and blocked");
            }
        }
    }
}
