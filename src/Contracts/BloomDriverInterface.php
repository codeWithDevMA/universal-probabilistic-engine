<?php

namespace Ghazali\UniversalProbabilisticEngine\Contracts;

interface BloomDriverInterface
{
    /**
     * Add a value to the filter and sync to persistent storage.
     */
    public function add(string $key, string $value): void;

    /**
     * Remove a value from the filter (only supported by Counting and Cuckoo drivers).
     *
     * For standard bit-only filters this is a no-op because bits cannot
     * be safely un-flipped (shared positions).  Counting drivers decrement
     * the counter; Cuckoo drivers remove the fingerprint from its bucket.
     */
    public function remove(string $key, string $value): void;

    /**
     * Return true if the value MIGHT be in the set (possible false positive).
     * Return false if the value is DEFINITELY NOT in the set (zero false negatives).
     */
    public function mightContain(string $key, string $value): bool;

    /**
     * Bulk warm-up: add many values at once more efficiently than individual add() calls.
     *
     * @param array<string> $values
     */
    public function warmup(string $key, array $values): void;

    /**
     * Destroy all filter data associated with the given key.
     */
    public function clear(string $key): void;
}
