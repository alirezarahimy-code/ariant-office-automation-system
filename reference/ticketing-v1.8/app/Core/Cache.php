<?php
declare(strict_types=1);

namespace App\Core;

class Cache
{
    private string $cachePath;
    private int $defaultTtl;

    public function __construct(string $cachePath = __DIR__ . '/../../storage/cache', int $defaultTtl = 3600)
    {
        $this->cachePath = $cachePath;
        $this->defaultTtl = $defaultTtl;
        
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0750, true);
        }
    }

    public function get(string $key): ?array
    {
        $file = $this->getCacheFile($key);
        
        if (!file_exists($file)) {
            return null;
        }
        
        $data = unserialize(file_get_contents($file));
        
        if ($data === false || !isset($data['expires']) || $data['expires'] < time()) {
            $this->delete($key);
            return null;
        }
        
        return $data['value'] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $file = $this->cachePath . '/' . $this->sanitizeKey($key) . '.cache';
        $data = [
            'expires' => time() + ($ttl ?? $this->defaultTtl),
            'value' => $value,
        ];
        
        return file_put_contents($file, serialize($data), LOCK_EX) !== false;
    }

    public function delete(string $key): bool
    {
        $file = $this->getCacheFile($key);
        
        if (file_exists($file)) {
            return unlink($file);
        }
        
        return false;
    }

    public function clear(): bool
    {
        $files = glob($this->cachePath . '/*.cache');
        
        if ($files === false) {
            return false;
        }
        
        foreach ($files as $file) {
            unlink($file);
        }
        
        return true;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }

    private function getCacheFile(string $key): string
    {
        return $this->cachePath . '/' . $this->sanitizeKey($key) . '.cache';
    }

    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
    }
}
