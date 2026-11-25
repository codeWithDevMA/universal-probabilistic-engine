<?php

namespace Ghazali\UniversalProbabilisticEngine\Drivers;

use Ghazali\UniversalProbabilisticEngine\Contracts\BloomDriverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cuckoo Filter backed by MySQL/PostgreSQL/SQLite.
 *
 * A Cuckoo filter stores short fingerprints (f bits) in a two-dimensional
 * bucket array.  Each value maps to two candidate buckets:
 *
 *   bucket1 = hash(value)          mod num_buckets
 *   bucket2 = bucket1 XOR hash(fingerprint)  mod num_buckets
 *
 * Lookup or deletion can use either bucket, which makes deletion safe even
 * without counters (unlike a standard Bloom filter).
 *
 * ┌─────────────────────────────────────────┐
 * │  Property            │ Value            │
 * │──────────────────────│──────────────────│
 * │  Fingerprint size    │ 8 bits (1 byte)  │
 * │  Slots per bucket    │ 4                │
 * │  Bytes per bucket    │ 4                │
 * │  False-positive rate │ ≈ 3 / (2^f * b) │
 * │    (f=8, b=4)        │ ≈ 0.3%           │
 * │  Supports deletion   │ ✅ yes            │
 * │  Storage (100k items)│ ≈ 200 KB         │
 * └─────────────────────────────────────────┘
 *
 * The entire bucket array is stored as a base64-encoded binary blob in the
 * `cuckoo_filters` table (one row per key).
 *
 * Cuckoo hashing displacement: when both candidate buckets are full, one
 * fingerprint is kicked out and relocated (max MAX_KICKS attempts).  If
 * relocation fails the insert is still recorded and logged — this is
 * extremely rare (only when filter is >96% full).
 */
class CuckooDriver implements BloomDriverInterface
{
    /** Slots per bucket. */
    private const BUCKET_SIZE = 4;

    /** Fingerprint byte value used to mark an empty slot. */
    private const EMPTY_SLOT = "\x00";

    /** Maximum cuckoo displacement kicks before giving up. */
    private const MAX_KICKS = 500;

    /** In-request memory cache: key => binary bucket array string */
    private array $cache = [];

    private int $numBuckets;

    public function __construct()
    {
        // num_buckets must be a power of 2 for efficient XOR alt-bucket calc.
        $numBuckets = (int) config('upe.cuckoo_buckets', 131_072); // 2^17 = 128k buckets
        // Snap to nearest power of 2
        $this->numBuckets = max(64, 1 << (int) ceil(log($numBuckets, 2)));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    public function add(string $key, string $value): void
    {
        $data        = $this->loadData($key);
        $fingerprint = $this->fingerprint($value);
        $b1          = $this->primaryBucket($value);
        $b2          = $this->altBucket($b1, $fingerprint);

        // Try primary bucket.
        if ($this->insertIntoSlot($data, $b1, $fingerprint)) {
            $this->persist($key, $data);
            return;
        }

        // Try alternate bucket.
        if ($this->insertIntoSlot($data, $b2, $fingerprint)) {
            $this->persist($key, $data);
            return;
        }

        // Both buckets full: cuckoo displacement.
        $bucket = random_int(0, 1) === 0 ? $b1 : $b2;

        for ($n = 0; $n < self::MAX_KICKS; $n++) {
            // Pick a random occupied slot and evict its fingerprint.
            $slot   = random_int(0, self::BUCKET_SIZE - 1);
            $offset = $bucket * self::BUCKET_SIZE + $slot;

            $evicted     = $data[$offset];
            $data[$offset] = chr($fingerprint);

            // The evicted fingerprint goes to its alternate bucket.
            $fingerprint = ord($evicted);
            $bucket      = $this->altBucket($bucket, $fingerprint);

            if ($this->insertIntoSlot($data, $bucket, $fingerprint)) {
                $this->persist($key, $data);
                return;
            }
        }

        // Extremely rare — filter is over capacity. Log and proceed.
        Log::warning('[upe:cuckoo] filter is full, insert failed after MAX_KICKS', [
            'key'         => $key,
            'num_buckets' => $this->numBuckets,
        ]);
        $this->persist($key, $data);
    }

    public function remove(string $key, string $value): void
    {
        $data        = $this->loadData($key);
        $fingerprint = $this->fingerprint($value);
        $b1          = $this->primaryBucket($value);
        $b2          = $this->altBucket($b1, $fingerprint);

        if ($this->removeFromBucket($data, $b1, $fingerprint)
            || $this->removeFromBucket($data, $b2, $fingerprint)) {
            $this->persist($key, $data);
            Log::debug('[upe:cuckoo] remove', ['key' => $key]);
            return;
        }

        Log::debug('[upe:cuckoo] remove: fingerprint not found (may have been displaced)', [
            'key' => $key,
        ]);
    }

    public function mightContain(string $key, string $value): bool
    {
        $data        = $this->loadData($key);
        $fingerprint = $this->fingerprint($value);
        $b1          = $this->primaryBucket($value);
        $b2          = $this->altBucket($b1, $fingerprint);

        return $this->bucketContains($data, $b1, $fingerprint)
            || $this->bucketContains($data, $b2, $fingerprint);
    }

    public function warmup(string $key, array $values): void
    {
        if (empty($values)) {
            return;
        }

        Log::info('[upe:cuckoo] warmup start', ['key' => $key, 'count' => count($values)]);

        // Preload once, batch all insertions, persist once.
        $data = $this->loadData($key);

        foreach ($values as $value) {
            $fp = $this->fingerprint((string) $value);
            $b1 = $this->primaryBucket((string) $value);
            $b2 = $this->altBucket($b1, $fp);

            if ($this->insertIntoSlot($data, $b1, $fp)
                || $this->insertIntoSlot($data, $b2, $fp)) {
                continue;
            }

            // Displacement during warmup (same algorithm as add()).
            $bucket = $b1;
            for ($n = 0; $n < self::MAX_KICKS; $n++) {
                $slot   = random_int(0, self::BUCKET_SIZE - 1);
                $offset = $bucket * self::BUCKET_SIZE + $slot;
                $evicted = $data[$offset];
                $data[$offset] = chr($fp);
                $fp     = ord($evicted);
                $bucket = $this->altBucket($bucket, $fp);
                if ($this->insertIntoSlot($data, $bucket, $fp)) {
                    break;
                }
            }
        }

        $this->cache[$key] = $data;
        $this->persist($key, $data);

        Log::info('[upe:cuckoo] warmup complete', ['key' => $key]);
    }

    public function clear(string $key): void
    {
        DB::table('cuckoo_filters')->where('key', $key)->delete();
        unset($this->cache[$key]);
        Log::info('[upe:cuckoo] cleared', ['key' => $key]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Bucket operations
    // ──────────────────────────────────────────────────────────────────────────

    /** Try to insert fingerprint into any empty slot of a bucket. */
    private function insertIntoSlot(string &$data, int $bucket, int $fingerprint): bool
    {
        $base = $bucket * self::BUCKET_SIZE;
        for ($s = 0; $s < self::BUCKET_SIZE; $s++) {
            if ($data[$base + $s] === self::EMPTY_SLOT) {
                $data[$base + $s] = chr($fingerprint);
                return true;
            }
        }
        return false;
    }

    /** Remove fingerprint from a bucket (set slot to EMPTY_SLOT). */
    private function removeFromBucket(string &$data, int $bucket, int $fingerprint): bool
    {
        $base = $bucket * self::BUCKET_SIZE;
        $fp   = chr($fingerprint);
        for ($s = 0; $s < self::BUCKET_SIZE; $s++) {
            if ($data[$base + $s] === $fp) {
                $data[$base + $s] = self::EMPTY_SLOT;
                return true;
            }
        }
        return false;
    }

    /** Check if a bucket contains a fingerprint. */
    private function bucketContains(string $data, int $bucket, int $fingerprint): bool
    {
        $base = $bucket * self::BUCKET_SIZE;
        $fp   = chr($fingerprint);
        for ($s = 0; $s < self::BUCKET_SIZE; $s++) {
            if ($data[$base + $s] === $fp) {
                return true;
            }
        }
        return false;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Hash functions
    // ──────────────────────────────────────────────────────────────────────────

    /** 8-bit fingerprint — must be non-zero (0x00 is the empty-slot sentinel). */
    private function fingerprint(string $value): int
    {
        $fp = hexdec(substr(hash('sha256', $value), 0, 2)) & 0xFF;
        return $fp === 0 ? 1 : $fp;  // never return 0
    }

    private function primaryBucket(string $value): int
    {
        return abs(hexdec(substr(hash('sha256', $value), 2, 8)) % $this->numBuckets);
    }

    /**
     * Alternate bucket: bucket XOR hash(fingerprint).
     * This is the standard Cuckoo filter formula from Fan et al. (2014).
     * The XOR ensures alt(alt(b, fp)) == b (symmetry).
     */
    private function altBucket(int $bucket, int $fingerprint): int
    {
        $fpHash = hexdec(substr(hash('sha256', chr($fingerprint)), 0, 8));
        return abs(($bucket ^ $fpHash) % $this->numBuckets);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Persistence
    // ──────────────────────────────────────────────────────────────────────────

    private function loadData(string $key): string
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $row = DB::table('cuckoo_filters')->where('key', $key)->first();

        if ($row !== null) {
            $data = base64_decode($row->data);
            $this->cache[$key] = $data;
            return $data;
        }

        // Allocate empty bucket array: num_buckets × BUCKET_SIZE bytes, all 0x00.
        $data = str_repeat(self::EMPTY_SLOT, $this->numBuckets * self::BUCKET_SIZE);
        DB::table('cuckoo_filters')->insert([
            'key'         => $key,
            'num_buckets' => $this->numBuckets,
            'data'        => base64_encode($data),
            'element_count' => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->cache[$key] = $data;
        return $data;
    }

    private function persist(string $key, string $data): void
    {
        $isEmpty = ($data === str_repeat(self::EMPTY_SLOT, $this->numBuckets * self::BUCKET_SIZE));

        if ($isEmpty) {
            DB::table('cuckoo_filters')
                ->where('key', $key)
                ->delete();
            unset($this->cache[$key]);
        } else {
            $this->cache[$key] = $data;
            DB::table('cuckoo_filters')
                ->where('key', $key)
                ->update([
                    'data'       => base64_encode($data),
                    'updated_at' => now(),
                ]);
        }
    }
}
