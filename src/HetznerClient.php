<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

/**
 * Hetzner Cloud API HTTP Client.
 *
 * Wraps cURL calls to https://api.hetzner.cloud/v1/ with Bearer token auth.
 * Used by all Layer 1 (Hetzner API) tools.
 */
class HetznerClient
{
    private const BASE_URL = 'https://api.hetzner.cloud/v1';
    private string $token;

    public function __construct(Config $config)
    {
        $this->token = $config->hetznerToken();
    }

    /**
     * GET request to Hetzner Cloud API.
     *
     * @param string $path  API path (e.g., '/servers/12345')
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
     * POST request to Hetzner Cloud API.
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
     * PUT request to Hetzner Cloud API.
     */
    public function put(string $path, array $data = []): array
    {
        return $this->request('PUT', self::BASE_URL . $path, $data);
    }

    /**
     * DELETE request to Hetzner Cloud API.
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', self::BASE_URL . $path);
    }

    private function request(string $method, string $url, ?array $data = null): array
    {
        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->token,
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
            $errMsg = $decoded['error']['message'] ?? 'Unknown Hetzner API error';
            $errCode = $decoded['error']['code'] ?? 'unknown';
            throw new \RuntimeException("Hetzner API error ({$errCode}): {$errMsg} [HTTP {$httpCode}]");
        }

        return $decoded;
    }
}
