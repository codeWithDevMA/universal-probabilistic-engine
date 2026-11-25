<?php

namespace Ghazali\UniversalProbabilisticEngine\Drivers;

use Ghazali\UniversalProbabilisticEngine\Contracts\BloomDriverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class DatabaseBitStringDriver implements BloomDriverInterface
{
    protected int $expectedElements;
    protected float $falsePositiveRate;
    protected int $m; // Optimal bit array size
    protected int $k; // Optimal number of hash functions

    /**
     * Local in-memory cache of bitstrings during request execution.
     */
    protected array $memoryCache = [];

    public function __construct()
    {
        $this->expectedElements = (int) config('upe.expected_elements', 100000);
        $this->falsePositiveRate = (float) config('upe.false_positive_rate', 0.01);

        try {
            // Calculate optimal bit array size: m = - (n * ln(p)) / (ln(2)^2)
            $this->m = (int) ceil(-($this->expectedElements * log($this->falsePositiveRate)) / (log(2) ** 2));

            // Calculate optimal number of hash functions: k = (m / n) * ln(2)
            $this->k = (int) max(1, round(($this->m / $this->expectedElements) * log(2)));

            Log::info('[upe:database-driver] initialized successfully', [
                'expected_elements' => $this->expectedElements,
                'false_positive_rate' => $this->falsePositiveRate,
                'calculated_bits_m' => $this->m,
                'calculated_hashes_k' => $this->k,
            ]);
        } catch (Exception $e) {
            Log::error('[upe:database-driver] failed during mathematical calculation of parameters', [
                'exception' => $e->getMessage()
            ]);
            // Safe fallbacks in case of mathematical limits
            $this->m = 1000000;
            $this->k = 7;
        }
    }

    /**
     * Add a value to the Bloom Filter.
     */
    public function add(string $key, string $value): void
    {
        $bitString = $this->getOrCreateBitString($key);
        $indices = $this->getHashIndices($value);

        $changed = false;
        foreach ($indices as $position) {
            if ($bitString[$position] !== '1') {
                $bitString[$position] = '1';
                $changed = true;
            }
        }

        if ($changed) {
            DB::table('bloom_filters')->updateOrInsert(
                ['key' => $key],
                [
                    'counter_string' => $bitString,
                    'expected_elements' => $this->expectedElements,
                    'false_positive_rate' => $this->falsePositiveRate,
                    'updated_at' => now(),
                ]
            );
            $this->memoryCache[$key] = $bitString;

            Log::debug('[upe:database-driver] added value to filter', [
                'key' => $key,
                'value' => $value,
                'flipped_positions' => $indices
            ]);
        }
    }

    /**
     * No-op for the standard bit-string driver.
     * Standard Bloom filters cannot decrement bits safely because multiple
     * values share bit positions.  Use CountingScalableBloomDriver instead.
     */
    public function remove(string $key, string $value): void
    {
        // Intentional no-op — standard bits cannot be un-flipped.
    }

    public function mightContain(string $key, string $value): bool
    {
        $bitString = $this->getOrCreateBitString($key);
        $indices = $this->getHashIndices($value);

        foreach ($indices as $position) {
            if ($bitString[$position] !== '1') {
                return false;
            }
        }

        return true;
    }

    /**
     * Bulk warm up the Bloom Filter with a list of values.
     */
    public function warmup(string $key, array $values): void
    {
        $bitString = $this->getOrCreateBitString($key);
        
        Log::info('[upe:database-driver] warming up filter', [
            'key' => $key,
            'count' => count($values)
        ]);

        foreach ($values as $val) {
            $indices = $this->getHashIndices((string) $val);
            foreach ($indices as $position) {
                $bitString[$position] = '1';
            }
        }

        DB::table('bloom_filters')->updateOrInsert(
            ['key' => $key],
            [
                'counter_string' => $bitString,
                'expected_elements' => $this->expectedElements,
                'false_positive_rate' => $this->falsePositiveRate,
                'updated_at' => now(),
            ]
        );

        $this->memoryCache[$key] = $bitString;
        Log::info('[upe:database-driver] warmup completed successfully', [
            'key' => $key
        ]);
    }

    /**
     * Clear the Bloom Filter associated with the key.
     */
    public function clear(string $key): void
    {
        DB::table('bloom_filters')->where('key', $key)->delete();
        unset($this->memoryCache[$key]);
        
        Log::info('[upe:database-driver] cleared filter', ['key' => $key]);
    }

    /**
     * Generate optimal hashing indices using salted CRC32 hashes.
     */
    protected function getHashIndices(string $value): array
    {
        $indices = [];
        for ($i = 0; $i < $this->k; $i++) {
            $hashHex = hash('crc32b', $value . '-' . $i);
            $hashInt = hexdec($hashHex);
            $indices[] = $hashInt % $this->m;
        }
        return $indices;
    }

    /**
     * Retrieve the bit string from cache/database, or initialize a new empty bit string.
     */
    protected function getOrCreateBitString(string $key): string
    {
        if (isset($this->memoryCache[$key])) {
            return $this->memoryCache[$key];
        }

        $row = DB::table('bloom_filters')->where('key', $key)->first();

        if ($row) {
            $this->memoryCache[$key] = $row->counter_string;
            return $row->counter_string;
        }

        // Initialize empty bit array of length m represented by character '0'
        $bitString = str_repeat('0', $this->m);
        
        DB::table('bloom_filters')->insert([
            'key' => $key,
            'counter_string' => $bitString,
            'expected_elements' => $this->expectedElements,
            'false_positive_rate' => $this->falsePositiveRate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->memoryCache[$key] = $bitString;
        return $bitString;
    }
}
