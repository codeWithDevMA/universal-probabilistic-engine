<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Bloom Filter Driver
    |--------------------------------------------------------------------------
    |
    | Controls which filter engine is used for all BloomManager operations.
    |
    | Supported values:
    |   "mysql"    — Counting + Scalable Bloom Filter (MySQL/PostgreSQL/SQLite)
    |                Supports deletion and auto-grows. Recommended default.
    |   "cuckoo"   — Cuckoo Filter (MySQL/PostgreSQL/SQLite)
    |                Better FP rate, true deletion, fingerprint buckets.
    |   "standard" — Legacy standard bit-string Bloom filter (no deletion).
    |   "redis"    — Redis Stack BF module (BF.ADD / BF.EXISTS).
    |
    */
    'default_driver' => env('UPE_DRIVER', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Counting / Scalable Bloom Filter Parameters
    |--------------------------------------------------------------------------
    |
    | expected_elements   (n) — How many distinct values you expect per filter
    |                           key over its entire lifetime.  The bit array
    |                           size m is derived from this.
    |
    | false_positive_rate (p) — Desired probability of a false positive at n
    |                           elements (0.01 = 1%).  Lower = more memory.
    |
    | fill_ratio_threshold    — When element_count / expected_elements exceeds
    |                           this fraction, a new Scalable slice is spawned
    |                           with false_positive_rate * 0.5.
    |                           Range: 0.0 – 1.0. Default: 0.8 (80% full).
    |
    */
    'expected_elements'    => (int)   env('UPE_EXPECTED_ELEMENTS',    100_000),
    'false_positive_rate'  => (float) env('UPE_FALSE_POSITIVE_RATE',  0.01),
    'fill_ratio_threshold' => (float) env('UPE_FILL_RATIO_THRESHOLD', 0.8),

    /*
    |--------------------------------------------------------------------------
    | Counter Bit Width  (CountingScalableBloomDriver only)
    |--------------------------------------------------------------------------
    |
    | Controls the maximum value a single counter position can reach before
    | it is capped.  The driver derives the alphabet size as 2^counter_bits,
    | clamped to 36 (the full base-36 charset: 0-9 + A-Z).
    |
    |  bits | alphabet size | cap | alphabet used
    |  -----+---------------+-----+----------------------------
    |    1  |     2         |   1 | 01
    |    2  |     4         |   3 | 0123
    |    3  |     8         |   7 | 01234567
    |    4  |    16         |  15 | 0123456789ABCDEF        ← default (hex)
    |    5  |    32         |  31 | 0123456789ABCDEFGHIJKLMNOPQRSTUV
    |    6  |    36 (max)   |  35 | 0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ
    |   7+  |    36 (max)   |  35 | (same, clamped)
    |
    | Storage cost is identical regardless of this setting (1 char per
    | position in counter_string).  Increase this if you expect extremely
    | high write contention on the same filter key.
    |
    */
    'counter_bits' => (int) env('UPE_COUNTER_BITS', 4),

    /*
    |--------------------------------------------------------------------------
    | Cuckoo Filter Parameters
    |--------------------------------------------------------------------------
    |
    | cuckoo_buckets — Total number of buckets in the filter array.
    |                  Must be a power of 2 (driver auto-snaps upward).
    |                  Default: 131072 (2^17).
    |
    |                  Memory = cuckoo_buckets × 4 bytes (4 slots × 1 byte fp).
    |                  131 072 × 4 = 512 KB — fits a ~200k-element filter at
    |                  ~0.3% false-positive rate.
    |
    */
    'cuckoo_buckets' => (int) env('UPE_CUCKOO_BUCKETS', 131_072),

];
