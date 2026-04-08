<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

/**
 * Hetzner DNS API HTTP Client.
 *
 * Wraps cURL calls to https://dns.hetzner.com/api/v1/ with Auth-API-Token header.
 * Separate from HetznerClient because the DNS API uses a different base URL and auth scheme.
 */
class DnsClient
{
    private const BASE_URL = 'https://dns.hetzner.com/api/v1';
    private string $token;

    public function __construct(Config $config)
    {
        $token = $config->dnsToken();
        if ($token === null) {
            throw new \RuntimeException('HETZNER_DNS_TOKEN is not set. DNS tools require a Hetzner DNS API token.');
        }
        $this->token = $token;
    }

    /**
     * GET request to Hetzner DNS API.
     *
     * @param string $path  API path (e.g., '/zones')
     * @param array  $query Query parameters
     * @return array Decoded JSON response
     * @throws \RuntimeException on API error
     */
    public function get(string $path, array $query = []): array
    {
        $url = self::BASE_URL . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    /**
     * POST request to Hetzner DNS API.
     *
     * @param string $path API path
     * @param array  $data Request body (will be JSON-encoded)
     * @return array Decoded JSON response
     */
    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', self::BASE_URL . $path, $data);
    }

    /**
     * PUT request to Hetzner DNS API.
     */
    public function put(string $path, array $data = []): array
    {
        return $this->request('PUT', self::BASE_URL . $path, $data);
    }

    /**
     * DELETE request to Hetzner DNS API.
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', self::BASE_URL . $path);
    }

    private function request(string $method, string $url, ?array $data = null): array
    {
        $ch = curl_init();

        $headers = [
            'Auth-API-Token: ' . $this->token,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data ? json_encode($data) : '{}');
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data ? json_encode($data) : '{}');
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("cURL error: {$error}");
        }

        $decoded = json_decode($response, true) ?? [];

        if (isset($decoded['error'])) {
            $errMsg = $decoded['error']['message'] ?? 'Unknown Hetzner DNS API error';
            $errCode = $decoded['error']['code'] ?? 'unknown';
            throw new \RuntimeException("Hetzner DNS API error ({$errCode}): {$errMsg} [HTTP {$httpCode}]");
        }

        return $decoded;
    }
}
