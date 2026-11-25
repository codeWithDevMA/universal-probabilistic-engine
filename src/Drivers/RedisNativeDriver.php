<?php

namespace Ghazali\UniversalProbabilisticEngine\Drivers;

use Ghazali\UniversalProbabilisticEngine\Contracts\BloomDriverInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Throwable;

class RedisNativeDriver implements BloomDriverInterface
{
    protected int $expectedElements;
    protected float $falsePositiveRate;

    public function __construct()
    {
        $this->expectedElements = (int) config('upe.expected_elements', 100000);
        $this->falsePositiveRate = (float) config('upe.false_positive_rate', 0.01);

        Log::info('[upe:redis-driver] initialized successfully', [
            'expected_elements' => $this->expectedElements,
            'false_positive_rate' => $this->falsePositiveRate,
        ]);
    }

    /**
     * Add a value to the Redis Bloom Filter.
     */
    public function add(string $key, string $value): void
    {
        $this->ensureReserved($key);

        try {
            Redis::executeRaw(['BF.ADD', $key, $value]);
            Log::debug('[upe:redis-driver] added value to filter', [
                'key' => $key,
                'value' => $value
            ]);
        } catch (Throwable $e) {
            Log::error('[upe:redis-driver] BF.ADD command failed', [
                'key' => $key,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * No-op for the Redis BF driver.
     * Redis Stack's BF module is a standard Bloom filter without deletion.
     * Use the CF (Cuckoo Filter) Redis module for deletion support.
     */
    public function remove(string $key, string $value): void
    {
        // Intentional no-op — Redis BF does not support deletion.
    }

    /**
     * Check if a value might be contained in the Redis Bloom Filter.
     */
    public function mightContain(string $key, string $value): bool
    {
        try {
            $result = Redis::executeRaw(['BF.EXISTS', $key, $value]);
            return (bool) $result;
        } catch (Throwable $e) {
            Log::error('[upe:redis-driver] BF.EXISTS command failed', [
                'key' => $key,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
            // Fallback to true (query actual DB) in case of driver errors to prevent false negatives
            return true;
        }
    }

    /**
     * Bulk warm up the Redis Bloom Filter.
     */
    public function warmup(string $key, array $values): void
    {
        $this->ensureReserved($key);

        if (empty($values)) {
            return;
        }

        Log::info('[upe:redis-driver] warming up filter', [
            'key' => $key,
            'count' => count($values)
        ]);

        try {
            // Redis BF.MADD allows adding multiple items in a single command
            $chunks = array_chunk($values, 1000);
            foreach ($chunks as $chunk) {
                $command = array_merge(['BF.MADD', $key], $chunk);
                Redis::executeRaw($command);
            }
            Log::info('[upe:redis-driver] warmup completed successfully', ['key' => $key]);
        } catch (Throwable $e) {
            Log::error('[upe:redis-driver] BF.MADD warmup failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clear the Redis Bloom Filter.
     */
    public function clear(string $key): void
    {
        try {
            Redis::del($key);
            Log::info('[upe:redis-driver] cleared filter', ['key' => $key]);
        } catch (Throwable $e) {
            Log::error('[upe:redis-driver] delete failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Ensure the Redis Bloom filter is reserved with configured math limits.
     */
    protected function ensureReserved(string $key): void
    {
        try {
            // Reserve filter if not exists. Redis BF.RESERVE throws error if key exists,
            // so we execute it inside a catch block or check existence first.
            $exists = Redis::exists($key);
            if (!$exists) {
                Redis::executeRaw([
                    'BF.RESERVE',
                    $key,
                    (string) $this->falsePositiveRate,
                    (string) $this->expectedElements
                ]);
                Log::info('[upe:redis-driver] reserved new Bloom filter key', [
                    'key' => $key,
                    'error_rate' => $this->falsePositiveRate,
                    'capacity' => $this->expectedElements
                ]);
            }
        } catch (Throwable $e) {
            // Ignore error if it states item already exists
            if (!str_contains(strtolower($e->getMessage()), 'already exists')) {
                Log::warning('[upe:redis-driver] BF.RESERVE attempt failed', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
