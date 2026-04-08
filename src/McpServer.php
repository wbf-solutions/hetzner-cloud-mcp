<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

/**
 * Core MCP Server.
 *
 * Handles the MCP protocol over two transports:
 * - SSE transport (GET ?mcp=sse → stream, POST with session_id → JSON-RPC)
 * - Streamable HTTP transport (POST with Accept negotiation)
 *
 * Methods: initialize, tools/list, tools/call, ping
 */
class McpServer
{
    private Config $config;
    private ToolRegistry $toolRegistry;
    private string $sessionId;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->toolRegistry = new ToolRegistry($config);
        $this->sessionId = bin2hex(random_bytes(16));
    }

    /**
     * Handle GET ?mcp=sse — Open SSE stream.
     * Sends the endpoint URL as the first event, then keeps the connection alive.
     */
    public function handleSseConnection(): void
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $path = strtok($_SERVER['REQUEST_URI'], '?');
        $key = $_GET['key'] ?? '';
        $postUrl = $key !== ''
            ? "{$path}?session_id={$this->sessionId}&key={$key}"
            : "{$path}?session_id={$this->sessionId}";

        $this->sendSseEvent('endpoint', $postUrl);

        $startTime = time();
        $maxLifetime = 300;

        while (true) {
            if (connection_aborted()) {
                break;
            }
            if ((time() - $startTime) > $maxLifetime) {
                break;
            }

            $messageFile = sys_get_temp_dir() . "/mcp-response-{$this->sessionId}.json";
            if (file_exists($messageFile)) {
                $response = file_get_contents($messageFile);
                unlink($messageFile);
                $this->sendSseEvent('message', $response);
            }

            echo ": keepalive\n\n";
            flush();

            usleep(500000);
        }
    }

    /**
     * Handle POST with session_id — Receive JSON-RPC request, process, write response for SSE stream.
     */
    public function handleJsonRpcRequest(): void
    {
        header('Content-Type: application/json');

        $sessionId = $_GET['session_id'] ?? '';
        if (!$this->isValidSessionId($sessionId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing session_id']);
            return;
        }

        $body = file_get_contents('php://input');
        $request = json_decode($body, true);

        if (!$request || !isset($request['jsonrpc'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON-RPC request']);
            return;
        }

        $response = $this->processJsonRpc($request);

        if ($response !== null) {
            $messageFile = sys_get_temp_dir() . "/mcp-response-{$sessionId}.json";
            file_put_contents($messageFile, json_encode($response), LOCK_EX);
        }

        http_response_code(202);
        echo json_encode(['status' => 'accepted']);
    }

    /**
     * Handle Streamable HTTP transport.
     *
     * POST with JSON-RPC body. Response format depends on Accept header:
     * - Accept: text/event-stream → SSE stream response
     * - Accept: application/json (or anything else) → synchronous JSON response
     *
     * Session management via Mcp-Session-Id header.
     */
    public function handleStreamableHttp(): void
    {
        $body = file_get_contents('php://input');
        $request = json_decode($body, true);

        if (!$request || !isset($request['jsonrpc'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid JSON-RPC request']);
            return;
        }

        $incomingSessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
        if ($incomingSessionId !== '' && !$this->isValidSessionId($incomingSessionId)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid Mcp-Session-Id header']);
            return;
        }
        $sessionId = $incomingSessionId !== '' ? $incomingSessionId : $this->sessionId;

        $response = $this->processJsonRpc($request);

        $accept = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';
        $wantsSse = str_contains($accept, 'text/event-stream');

        if ($wantsSse) {
            while (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            header("Mcp-Session-Id: {$sessionId}");

            if ($response !== null) {
                $this->sendSseEvent('message', json_encode($response));
            }

            flush();
        } else {
            header('Content-Type: application/json');
            header("Mcp-Session-Id: {$sessionId}");

            if ($response !== null) {
                echo json_encode($response);
            } else {
                http_response_code(204);
            }
        }
    }

    /**
     * Process a JSON-RPC message and return the response (or null for notifications).
     */
    private function processJsonRpc(array $request): ?array
    {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        if ($id === null) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->handleInitialize($id, $params),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $params),
            'ping' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => []],
            default => [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => "Method not found: {$method}"],
            ],
        };
    }

    private function handleInitialize(int|string $id, array $params): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [
                    'tools' => new \stdClass(),
                ],
                'serverInfo' => [
                    'name' => 'hetzner-cloud-mcp',
                    'version' => '1.0.0',
                    'instance' => $this->config->instanceName(),
                    'manages' => array_keys($this->config->servers()),
                    'icons' => $this->getIcons(),
                ],
            ],
        ];
    }

    private function handleToolsList(int|string $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => $this->toolRegistry->listTools(),
            ],
        ];
    }

    private function handleToolsCall(int|string $id, array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        try {
            $result = $this->toolRegistry->callTool($toolName, $arguments);
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT)],
                    ],
                ],
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32602, 'message' => $e->getMessage()],
            ];
        } catch (\Throwable $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => "Error: {$e->getMessage()}"],
                    ],
                    'isError' => true,
                ],
            ];
        }
    }

    private function sendSseEvent(string $event, string $data): void
    {
        echo "event: {$event}\n";
        foreach (explode("\n", $data) as $line) {
            echo "data: {$line}\n";
        }
        echo "\n";
        flush();
    }

    private function getIcons(): array
    {
        $iconPath = __DIR__ . '/../public/icons/hetzner-cloud-mcp-256.png';
        if (!file_exists($iconPath)) {
            return [];
        }

        $data = base64_encode(file_get_contents($iconPath));
        return [
            [
                'src' => 'data:image/png;base64,' . $data,
                'mimeType' => 'image/png',
                'sizes' => ['256x256'],
            ],
        ];
    }

    private function isValidSessionId(string $id): bool
    {
        return $id !== '' && (bool) preg_match('/^[a-f0-9]{32}$/i', $id);
    }
}
