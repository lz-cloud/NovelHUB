<?php
require_once __DIR__ . '/../config.php';

class Cache
{
    private string $dir;

    public function __construct(string $dir = CACHE_DIR)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) @mkdir($this->dir, 0775, true);
    }

    private function path(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $key);
        return $this->dir . '/' . $safe . '.cache';
    }

    public function set(string $key, $value, int $ttl = 300): bool
    {
        $file = $this->path($key);
        $payload = ['expires' => time() + $ttl, 'value' => $value];
        return (bool)file_put_contents($file, serialize($payload));
    }

    public function get(string $key, $default = null)
    {
        $file = $this->path($key);
        if (!file_exists($file)) return $default;
        $raw = @file_get_contents($file);
        if (!$raw) return $default;
        $payload = @unserialize($raw);
        if (!is_array($payload) || !isset($payload['expires'])) return $default;
        if ($payload['expires'] < time()) { @unlink($file); return $default; }
        return $payload['value'];
    }

    public function remember(string $key, int $ttl, callable $producer)
    {
        $existing = $this->get($key, null);
        if ($existing !== null) return $existing;
        $value = $producer();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function clear(): void
    {
        foreach (glob($this->dir.'/*.cache') as $f) @unlink($f);
    }
}
