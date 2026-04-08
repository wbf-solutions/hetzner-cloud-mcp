<?php

declare(strict_types=1);

/**
 * Hetzner Cloud MCP Server — Entry Point
 *
 * Supports two MCP transports:
 * - SSE transport:        GET ?mcp=sse&key=XXX → SSE stream; POST ?session_id=X&key=XXX → JSON-RPC
 * - Streamable HTTP:      POST (no ?mcp=sse, no ?session_id) with Authorization header → JSON-RPC
 */

require_once __DIR__ . '/vendor/autoload.php';

use WBFSolutions\HetznerMcp\Config;
use WBFSolutions\HetznerMcp\Auth;
use WBFSolutions\HetznerMcp\RateLimiter;
use WBFSolutions\HetznerMcp\McpServer;

$config = new Config(__DIR__);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$rateLimiter = new RateLimiter($config);
if (!$rateLimiter->check($_SERVER['REMOTE_ADDR'])) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}

$auth = new Auth($config);
$authRequired = $config->mcpApiKey() !== '' || $config->oauthEnabled();

$key = $_GET['key'] ?? '';
if ($key === '') {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $key = substr($authHeader, 7);
    }
}

if ($authRequired && !$auth->validate($key)) {
    http_response_code(401);
    header('WWW-Authenticate: ' . $auth->wwwAuthenticateHeader());
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$mcp = new McpServer($config);

// SSE transport: GET ?mcp=sse
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['mcp'] ?? '') === 'sse') {
    $mcp->handleSseConnection();
    exit;
}

// SSE transport: POST with session_id (response delivered via SSE stream)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['session_id'])) {
    $mcp->handleJsonRpcRequest();
    exit;
}

// Streamable HTTP transport: POST without session_id query param
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mcp->handleStreamableHttp();
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid request. Use GET ?mcp=sse for SSE transport or POST for Streamable HTTP.']);
