<?php

namespace Ghazali\UniversalProbabilisticEngine;

use Illuminate\Support\Manager;
use Ghazali\UniversalProbabilisticEngine\Drivers\DatabaseBitStringDriver;
use Ghazali\UniversalProbabilisticEngine\Drivers\CountingScalableBloomDriver;
use Ghazali\UniversalProbabilisticEngine\Drivers\CuckooDriver;
use Ghazali\UniversalProbabilisticEngine\Drivers\RedisNativeDriver;
use Ghazali\UniversalProbabilisticEngine\Contracts\BloomDriverInterface;

class BloomManager extends Manager
{
    /**
     * Get the default driver name from config.
     */
    public function getDefaultDriver(): string
    {
        return config('upe.default_driver', 'mysql');
    }

    /**
     * Counting + Scalable Bloom Filter (MySQL / PostgreSQL / SQLite).
     *
     * This is the recommended driver.  It supports:
     *   - Deletion       via hex-nibble counters (Counting BF)
     *   - Infinite growth via automatic slice spawning (Scalable BF)
     *   - Double-hashing via Kirsch-Mitzenmacher (less clustering than CRC32)
     *
     * Set UPE_DRIVER=mysql in .env to use this driver.
     */
    public function createMysqlDriver(): BloomDriverInterface
    {
        return new CountingScalableBloomDriver();
    }

    /**
     * Cuckoo Filter (MySQL / PostgreSQL / SQLite).
     *
     * Better false-positive rate at the same memory, true deletion support,
     * fingerprint-based bucket storage.
     *
     * Set UPE_DRIVER=cuckoo in .env to use this driver.
     */
    public function createCuckooDriver(): BloomDriverInterface
    {
        return new CuckooDriver();
    }

    /**
     * Legacy standard bit-string Bloom filter (no deletion, fixed size).
     * Kept for backward compatibility.
     *
     * Set UPE_DRIVER=standard in .env to use this driver.
     */
    public function createStandardDriver(): BloomDriverInterface
    {
        return new DatabaseBitStringDriver();
    }

    /**
     * Redis Stack native Bloom filter (BF.ADD / BF.EXISTS commands).
     *
     * Set UPE_DRIVER=redis in .env to use this driver.
     */
    public function createRedisDriver(): BloomDriverInterface
    {
        return new RedisNativeDriver();
    }
}
