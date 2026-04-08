<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

class Auth
{
    private string $apiKey;
    private Config $config;

    public function __construct(Config $config)
    {
        $this->apiKey = $config->mcpApiKey();
        $this->config = $config;
    }

    /**
     * Validate a Bearer token. Tries static API key first, then OAuth introspection.
     */
    public function validate(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        if ($this->apiKey !== '' && hash_equals($this->apiKey, $token)) {
            return true;
        }

        if ($this->config->oauthEnabled()) {
            return $this->introspect($token);
        }

        return false;
    }

    /**
     * Validate an OAuth access token via the introspection endpoint (RFC 7662).
     */
    private function introspect(string $token): bool
    {
        $url = $this->config->oauthIntrospectUrl();
        $clientId = $this->config->oauthClientId();
        $clientSecret = $this->config->oauthClientSecret();

        if ($url === '' || $clientId === '') {
            return false;
        }

        $postFields = http_build_query(['token' => $token]);
        $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authHeader,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return false;
        }

        return ($data['active'] ?? false) === true;
    }

    /**
     * Return the WWW-Authenticate header value for 401 responses (RFC 9728).
     */
    public function wwwAuthenticateHeader(): string
    {
        $resourceUrl = $this->config->oauthResourceUrl();
        if ($resourceUrl === '') {
            return 'Bearer';
        }
        return 'Bearer resource_metadata="' . $resourceUrl . '/.well-known/oauth-protected-resource"';
    }
}
