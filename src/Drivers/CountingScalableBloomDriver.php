<?php

namespace Ghazali\UniversalProbabilisticEngine\Drivers;

use Ghazali\UniversalProbabilisticEngine\Contracts\BloomDriverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Counting + Scalable Bloom Filter backed by a single MySQL/PostgreSQL/SQLite table.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ COUNTING                                                                │
 * │ Each bit position stores one character from a configurable base-N       │
 * │ alphabet (0–9, A–Z) instead of a plain 0/1.                            │
 * │                                                                         │
 * │ The alphabet size — and therefore the counter cap — is controlled by    │
 * │ UPE_COUNTER_BITS in .env (default: 4 → cap 15, same as classic hex).   │
 * │                                                                         │
 * │ Alphabet: '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ' (36 chars, base-36)   │
 * │   bits=4  → use first 16 chars (0-F)  → cap = 15  (default)           │
 * │   bits=5  → use first 32 chars (0-V)  → cap = 31                      │
 * │   bits=6+ → capped at 36 chars (0-Z)  → cap = 35  (maximum)           │
 * │                                                                         │
 * │ - add()    → increments each of the k positions (caps at counterMax)   │
 * │ - remove() → decrements each position (floors at '0')                  │
 * │ - mightContain() → all k positions must be > '0'                       │
 * │                                                                         │
 * │ Memory trade-off: 1 char per position (same storage for any cap).       │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │ SCALABLE                                                                │
 * │ Each (key, slice_index) pair is one filter slice.                       │
 * │ When element_count ≥ expected_elements × fill_ratio_threshold a new    │
 * │ slice is spawned with false_positive_rate × 0.5 (tighter each time).   │
 * │ - add()          → writes to latest slice only                          │
 * │ - mightContain() → queries ALL slices (true if any slice says "yes")   │
 * │ - remove()       → finds the slice where all k positions are set,       │
 * │                    then decrements in that slice                        │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Required migration columns:
 *   $table->string('key');
 *   $table->unsignedTinyInteger('slice_index')->default(0);
 *   $table->mediumText('counter_string')->nullable();
 *   $table->unsignedInteger('element_count')->default(0);
 *   $table->unsignedInteger('expected_elements');
 *   $table->double('false_positive_rate');
 *   $table->unique(['key', 'slice_index']);
 */
class CountingScalableBloomDriver implements BloomDriverInterface
{
    /** Default bit array size when the math formula cannot be applied. */
    private const FALLBACK_M = 1_000_000;
    private const FALLBACK_K = 7;

    /**
     * Full base-36 alphabet: digits 0-9 then uppercase A-Z.
     * UPE_COUNTER_BITS determines how many characters are used:
     *   bits=4 → first 16 chars (0-F)  cap=15
     *   bits=5 → first 32 chars (0-V)  cap=31
     *   bits=6 → all 36 chars   (0-Z)  cap=35  (max)
     */
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Number of usable symbols, derived from UPE_COUNTER_BITS. */
    private int $counterSize;

    /** Maximum counter value (counterSize - 1). */
    private int $counterMax;

    /** In-request memory cache: [key][slice_index] => counter_string */
    private array $cache = [];

    private int   $expectedElements;
    private float $falsePositiveRate;
    private float $fillRatioThreshold;
    private int   $m;
    private int   $k;

    public function __construct()
    {
        $this->expectedElements   = (int)   config('upe.expected_elements',    100_000);
        $this->falsePositiveRate  = (float) config('upe.false_positive_rate',  0.01);
        $this->fillRatioThreshold = (float) config('upe.fill_ratio_threshold', 0.8);

        // Derive counter alphabet size from UPE_COUNTER_BITS.
        // bits=4 → 2^4=16 symbols → cap=15  (classic hex, default)
        // bits=5 → 2^5=32 symbols → cap=31
        // bits=6 → 2^6=64, clamped to max alphabet size 36 → cap=35
        $bits = max(1, (int) config('upe.counter_bits', 4));
        $this->counterSize = min((int) pow(2, $bits), strlen(self::ALPHABET));
        $this->counterMax  = $this->counterSize - 1;

        [$this->m, $this->k] = $this->computeMK($this->expectedElements, $this->falsePositiveRate);

        Log::info('[upe:counting-scalable] initialized', [
            'm'                    => $this->m,
            'k'                    => $this->k,
            'expected_elements'    => $this->expectedElements,
            'false_positive_rate'  => $this->falsePositiveRate,
            'fill_ratio_threshold' => $this->fillRatioThreshold,
            'counter_bits'         => $bits,
            'counter_max'          => $this->counterMax,
            'alphabet_used'        => substr(self::ALPHABET, 0, $this->counterSize),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    public function add(string $key, string $value): void
    {
        $slice = $this->getOrCreateWritableSlice($key);

        $counterStr = $this->loadCounterString($key, $slice->slice_index, $slice->m);
        $indices    = $this->hashIndices($value, $slice->k, $slice->m);
        $changed    = false;

        foreach ($indices as $pos) {
            $current = strpos(self::ALPHABET, $counterStr[$pos]);
            if ($current < $this->counterMax) {        // cap at counterMax
                $counterStr[$pos] = self::ALPHABET[$current + 1];
                $changed = true;
            }
        }

        $newCount = $slice->element_count + 1;

        DB::table('bloom_filters')->updateOrInsert(
            ['key' => $key, 'slice_index' => $slice->slice_index],
            [
                'counter_string'    => $counterStr,
                'element_count'     => $newCount,
                'expected_elements' => $slice->expected_elements,
                'false_positive_rate' => $slice->false_positive_rate,
                'updated_at'        => now(),
            ]
        );

        $this->cache[$key][$slice->slice_index] = $counterStr;

        if ($changed) {
            Log::debug('[upe:counting-scalable] add', [
                'key'         => $key,
                'slice'       => $slice->slice_index,
                'element_count' => $newCount,
            ]);
        }
    }

    public function remove(string $key, string $value): void
    {
        $slices = $this->allSlices($key);

        foreach ($slices as $slice) {
            $counterStr = $this->loadCounterString($key, $slice->slice_index, $slice->m);
            $indices    = $this->hashIndices($value, $slice->k, $slice->m);

            // Only decrement if all k positions are non-zero in this slice.
            $allSet = true;
            foreach ($indices as $pos) {
                if ($counterStr[$pos] === '0') {
                    $allSet = false;
                    break;
                }
            }

            if (!$allSet) {
                continue;
            }

            foreach ($indices as $pos) {
                $current = strpos(self::ALPHABET, $counterStr[$pos]);
                $counterStr[$pos] = self::ALPHABET[max(0, $current - 1)];
            }

            $newCount = max(0, $slice->element_count - 1);

            if ($newCount === 0) {
                DB::table('bloom_filters')
                    ->where('key', $key)
                    ->where('slice_index', $slice->slice_index)
                    ->delete();
                unset($this->cache[$key][$slice->slice_index]);
            } else {
                DB::table('bloom_filters')
                    ->where('key', $key)
                    ->where('slice_index', $slice->slice_index)
                    ->update([
                        'counter_string' => $counterStr,
                        'element_count'  => $newCount,
                        'updated_at'     => now(),
                    ]);
                $this->cache[$key][$slice->slice_index] = $counterStr;
            }

            Log::debug('[upe:counting-scalable] remove', [
                'key'   => $key,
                'slice' => $slice->slice_index,
                'new_count' => $newCount,
            ]);

            return; // Only remove from first matching slice.
        }
    }

    public function mightContain(string $key, string $value): bool
    {
        $slices = $this->allSlices($key);

        if (empty($slices)) {
            return false;
        }

        foreach ($slices as $slice) {
            $counterStr = $this->loadCounterString($key, $slice->slice_index, $slice->m);
            $indices    = $this->hashIndices($value, $slice->k, $slice->m);

            $foundInSlice = true;
            foreach ($indices as $pos) {
                if ($counterStr[$pos] === '0') {
                    $foundInSlice = false;
                    break;
                }
            }

            if ($foundInSlice) {
                return true;
            }
        }

        return false;
    }

    public function warmup(string $key, array $values): void
    {
        if (empty($values)) {
            return;
        }

        Log::info('[upe:counting-scalable] warmup start', [
            'key'   => $key,
            'count' => count($values),
        ]);

        foreach ($values as $value) {
            $this->add($key, (string) $value);
        }

        Log::info('[upe:counting-scalable] warmup complete', ['key' => $key]);
    }

    public function clear(string $key): void
    {
        DB::table('bloom_filters')->where('key', $key)->delete();
        unset($this->cache[$key]);
        Log::info('[upe:counting-scalable] cleared', ['key' => $key]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Scalable slice management
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Return the slice that should receive new writes.
     * Creates the first slice (index 0) on first use.
     * Spawns a new slice when the current one is >= fill_ratio_threshold full.
     */
    private function getOrCreateWritableSlice(string $key): object
    {
        $latest = DB::table('bloom_filters')
            ->where('key', $key)
            ->orderByDesc('slice_index')
            ->first();

        // First use: create slice 0.
        if ($latest === null) {
            return $this->createSlice($key, 0, $this->expectedElements, $this->falsePositiveRate);
        }

        $fillRatio = $latest->expected_elements > 0
            ? $latest->element_count / $latest->expected_elements
            : 1.0;

        // Current slice full: spawn a new one with tighter FP rate.
        if ($fillRatio >= $this->fillRatioThreshold) {
            $newIndex      = $latest->slice_index + 1;
            $newFpRate     = max(0.0001, $latest->false_positive_rate * 0.5);
            $newExpected   = $this->expectedElements;

            Log::info('[upe:counting-scalable] spawning new slice', [
                'key'         => $key,
                'new_slice'   => $newIndex,
                'fp_rate'     => $newFpRate,
            ]);

            return $this->createSlice($key, $newIndex, $newExpected, $newFpRate);
        }

        // Attach computed m/k so the caller does not need to re-derive them.
        $latest->m = $this->mFromRow($latest);
        $latest->k = $this->kFromRow($latest);
        return $latest;
    }

    private function createSlice(
        string $key,
        int    $sliceIndex,
        int    $expectedElements,
        float  $falsePositiveRate
    ): object {
        [$m, $k] = $this->computeMK($expectedElements, $falsePositiveRate);
        $counterStr = str_repeat('0', $m);

        DB::table('bloom_filters')->insert([
            'key'                 => $key,
            'slice_index'         => $sliceIndex,
            'counter_string'      => $counterStr,
            'element_count'       => 0,
            'expected_elements'   => $expectedElements,
            'false_positive_rate' => $falsePositiveRate,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->cache[$key][$sliceIndex] = $counterStr;

        return (object) [
            'slice_index'         => $sliceIndex,
            'element_count'       => 0,
            'expected_elements'   => $expectedElements,
            'false_positive_rate' => $falsePositiveRate,
            'm'                   => $m,
            'k'                   => $k,
        ];
    }

    /** @return object[] all slices for key, ordered by slice_index ASC */
    private function allSlices(string $key): array
    {
        return DB::table('bloom_filters')
            ->where('key', $key)
            ->orderBy('slice_index')
            ->get()
            ->map(function ($row) {
                $row->m = $this->mFromRow($row);
                $row->k = $this->kFromRow($row);
                return $row;
            })
            ->all();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Counter string helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function loadCounterString(string $key, int $sliceIndex, int $m): string
    {
        if (isset($this->cache[$key][$sliceIndex])) {
            return $this->cache[$key][$sliceIndex];
        }

        $row = DB::table('bloom_filters')
            ->where('key', $key)
            ->where('slice_index', $sliceIndex)
            ->value('counter_string');

        $counterStr = $row ?? str_repeat('0', $m);
        $this->cache[$key][$sliceIndex] = $counterStr;
        return $counterStr;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Hash functions
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Generate k bit positions for the given value using double-hashing
     * (SHA-256 + CRC32) to avoid the clustering that pure CRC32 exhibits.
     *
     * @return int[]
     */
    private function hashIndices(string $value, int $k, int $m): array
    {
        // Two independent hash seeds
        $h1 = hexdec(substr(hash('sha256', $value), 0, 8));
        $h2 = hexdec(hash('crc32b', $value));

        $indices = [];
        for ($i = 0; $i < $k; $i++) {
            // Kirsch-Mitzenmacher extension: g(i) = h1 + i*h2 (mod m)
            $indices[] = abs(($h1 + $i * $h2) % $m);
        }
        return $indices;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Optimal parameter math
    // ──────────────────────────────────────────────────────────────────────────

    /** @return array{int,int} [m, k] */
    private function computeMK(int $n, float $p): array
    {
        if ($n <= 0 || $p <= 0 || $p >= 1) {
            return [self::FALLBACK_M, self::FALLBACK_K];
        }

        try {
            $m = (int) ceil(-($n * log($p)) / (log(2) ** 2));
            $k = (int) max(1, round(($m / $n) * log(2)));
            return [$m, $k];
        } catch (\Throwable) {
            return [self::FALLBACK_M, self::FALLBACK_K];
        }
    }

    private function mFromRow(object $row): int
    {
        return $this->computeMK((int) $row->expected_elements, (float) $row->false_positive_rate)[0];
    }

    private function kFromRow(object $row): int
    {
        return $this->computeMK((int) $row->expected_elements, (float) $row->false_positive_rate)[1];
    }
}
