# Universal Probabilistic Engine (UPE) for Laravel

[![Latest Stable Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://packagist.org/packages/ghazali/universal-probabilistic-engine)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)

The **Universal Probabilistic Engine (UPE)** is a high-performance, developer-friendly Laravel package that provides **O(1) Bloom Filter shielding** and high-speed CRUD operations. It works universally across databases that lack native Bloom filters (MySQL, SQLite, PostgreSQL) using an optimized bitstring fallback storage system, while seamlessly scaling to native Bloom-capable databases (like Redis) via a unified Driver Manager.

---

## Key Features

- ⚡ **O(1) Bloom Filter Shielding**: Fast-reject non-existent lookup requests (e.g. searching for invalid emails, usernames, or API keys) before querying the database, preventing DB resource exhaustion.
- ⚙️ **Write-Through Synchronization**: All CRUD operations update both the primary database and the Bloom filter synchronously, ensuring zero read-latency penalty.
- 🛡️ **Zero Data Loss & Collision Safety**: Handles Bloom filter false positives elegantly. If a key is reported as "might be present" (due to hash collisions), UPE queries the database and verifies existence. Requires a `UNIQUE` or `INDEX` key on the target column for instant collision resolution.
- 🗄️ **Optimized Bitstring Driver**: For MySQL/SQLite/PostgreSQL, UPE manages a memory-efficient bitstring inside a `MEDIUMTEXT` column.
- 🔴 **Redis Native Driver**: Scales instantly to Redis utilizing native Bloom filter module commands (`BF.RESERVE`, `BF.ADD`, `BF.EXISTS`).
- 🧹 **Drift Self-Healing**: Automatically handles database deletions. Since Bloom filters do not support deletion, UPE intercepts "deleted" hits, queries the DB, yields a null result, and logs the occurrence without database corruptions.
- 🚀 **Artisan Warmup Command**: Seed your Bloom filters in chunks from existing database tables with a single command.

---

## How It Works

### Mathematical Tuning

A Bloom filter is parameterized by:
- $n$: The expected number of items to insert.
- $p$: The desired false positive rate (e.g., `0.01` for $1\%$).

UPE dynamically calculates the optimal size of the bit array ($m$) and the number of hash functions ($k$):
\[m = \left\lceil - \frac{n \ln(p)}{(\ln 2)^2} \right\rceil\]
\[k = \max\left(1, \text{round}\left(\frac{m}{n} \ln 2\right)\right)\]

These parameters are configured in `config/upe.php` and applied dynamically by the drivers.

### Hashing Strategy

To distribute bits uniformly without the overhead of heavy cryptographic algorithms, UPE uses salted `CRC32` hash values combined with modulo arithmetic:
\[\text{BitIndex}_i = (\text{crc32}(value \cdot i \cdot salt) \pmod m)\]
This produces $k$ distinct, highly uniform bit indices to set/check in the bitstring.

---

## Installation

### 1. Install via Composer

Add the repository configuration to your host project's `composer.json` (if installing locally) or require it directly:

```json
"repositories": [
    {
        "type": "path",
        "url": "C:/laragon/www/universal-probabilistic-engine"
    }
]
```

Then run:

```bash
composer require ghazali/universal-probabilistic-engine
```

### 2. Publish Configuration & Migrations

Publish the package configuration and database migration file to your Laravel application:

```bash
php artisan vendor:publish --provider="Ghazali\UniversalProbabilisticEngine\UpeServiceProvider"
```

### 3. Run Migrations

Execute the migration to create the `bloom_filters` table used by the fallback driver:

```bash
php artisan migrate
```

---

## Configuration

The configuration file is published at `config/upe.php`. You can customize the driver, expected elements, and false positive rates:

```php
return [
    // Default driver: 'mysql' or 'redis'
    'default_driver' => env('UPE_DRIVER', 'mysql'),

    // Expected number of records in the Bloom filter
    'expected_elements' => (int) env('UPE_EXPECTED_ELEMENTS', 100000),

    // Allowed False Positive Rate (0.01 = 1% false positive probability)
    'false_positive_rate' => (float) env('UPE_FALSE_POSITIVE_RATE', 0.01),

    // Redis connection configuration
    'redis' => [
        'connection' => 'default',
    ],
];
```

---

## Seeding the Filter (Warmup Command)

If you already have existing data in a database table, you can seed the Bloom filter using the `upe:warmup` command. It uses `chunkById` under the hood to handle millions of records without running out of memory:

```bash
php artisan upe:warmup --table=users --column=email
```

---

## Basic Usage

Instantiate the `FastCrudEngine` by passing the target database table name and the indexed column name:

```php
use Ghazali\UniversalProbabilisticEngine\FastCrudEngine;

// Create engine instance
$engine = new FastCrudEngine('users', 'email');

// 1. Create a record (Write-Through)
$user = $engine->create([
    'email' => 'alice@example.com',
    'name'  => 'Alice Smith',
    'created_at' => now(),
    'updated_at' => now(),
]);

// 2. Find a record (O(1) Shielded)
// - If the email is NOT in the Bloom Filter, it returns NULL instantly without hitting the DB.
// - If the email IS in the Bloom Filter, it queries the DB to fetch the row and resolve false positives.
$user = $engine->find('alice@example.com');

if ($user) {
    echo "User found: " . $user['name'];
}

// 3. Update a record
$engine->update('alice@example.com', [
    'email' => 'alice.new@example.com',
    'name'  => 'Alice Cooper',
]);

// 4. Delete a record
// - Deletes from the database.
// - Subsequent find() queries for 'alice.new@example.com' will hit the Bloom filter (which still has set bits),
//   but when UPE queries the database, it receives NULL. UPE safely handles this drift.
$engine->delete('alice.new@example.com');
```

---

## License

This package is open-source software licensed under the [MIT license](LICENSE.md).
