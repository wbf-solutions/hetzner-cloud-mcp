<?php

declare(strict_types=1);

namespace WBFSolutions\HetznerMcp;

class RateLimiter
{
    private int $maxRequests;
    private string $storageDir;

    public function __construct(Config $config)
    {
        $this->maxRequests = $config->rateLimitPerMinute();
        $this->storageDir = $config->rateLimitStorageDir();

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0700, true);
        }
    }

    /**
     * Check if the given IP is within the rate limit.
     * Uses flock() to prevent race conditions under concurrent requests.
     */
    public function check(string $ip): bool
    {
        $this->cleanup();

        $file = $this->storageDir . '/' . md5($ip) . '.json';
        $now = time();
        $windowStart = $now - 60;

        $fp = fopen($file, 'c+');
        if ($fp === false) {
            return false;
        }

        $allowed = true;

        try {
            if (!flock($fp, LOCK_EX)) {
                return false;
            }

            $contents = stream_get_contents($fp);
            $requests = [];
            if ($contents !== '' && $contents !== false) {
                $data = json_decode($contents, true);
                if (is_array($data)) {
                    $requests = array_filter($data, fn(int $ts) => $ts > $windowStart);
                }
            }

            if (count($requests) >= $this->maxRequests) {
                $allowed = false;
            } else {
                $requests[] = $now;
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode(array_values($requests)));
                fflush($fp);
            }

            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        return $allowed;
    }

    private function cleanup(): void
    {
        static $lastCleanup = 0;
        $now = time();

        if ($now - $lastCleanup < 60) {
            return;
        }
        $lastCleanup = $now;

        $cutoff = $now - 120;
        foreach (glob($this->storageDir . '/*.json') as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
