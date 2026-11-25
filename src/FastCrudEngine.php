<?php

namespace Ghazali\UniversalProbabilisticEngine;

use Ghazali\UniversalProbabilisticEngine\Contracts\BloomDriverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FastCrudEngine
{
    protected BloomDriverInterface $driver;
    protected string $bloomKey;

    public function __construct(
        protected string $table,
        protected string $field
    ) {
        $this->bloomKey = "{$table}:{$field}";
        $this->driver = app(BloomManager::class)->driver();

        $this->verifyIndex();
    }

    /**
     * Write-Through Create: Inserts record to database and immediately adds value to Bloom filter.
     */
    public function create(array $attributes): array
    {
        $value = $attributes[$this->field] ?? null;

        if ($value === null) {
            throw new \InvalidArgumentException("Target field '{$this->field}' must be provided in attributes.");
        }

        // 1. Write to primary database storage layer
        $id = DB::table($this->table)->insertGetId($attributes);
        $attributes['id'] = $id;

        // 2. Synchronously write/flip bits in the Bloom filter (Write-Through Sync)
        $this->driver->add($this->bloomKey, (string) $value);

        Log::info('[upe:engine] write-through create completed', [
            'table' => $this->table,
            'field' => $this->field,
            'value' => $value,
            'inserted_id' => $id
        ]);

        return $attributes;
    }

    /**
     * Probabilistic Find: Checks Bloom filter first. If false, rejects instantly (O(1)).
     * Otherwise queries the database (resolving any false positive collisions).
     */
    public function find(mixed $value): ?array
    {
        $valStr = (string) $value;

        // 1. O(1) Bloom Filter Shielding Check
        if (! $this->driver->mightContain($this->bloomKey, $valStr)) {
            Log::debug('[upe:engine] O(1) shield fast-rejection (not in filter)', [
                'table' => $this->table,
                'field' => $this->field,
                'value' => $valStr
            ]);
            return null;
        }

        // 2. Might contain (True or False Positive). Query the database.
        // The column must have UNIQUE or INDEX keys to resolve this instantly.
        $row = DB::table($this->table)->where($this->field, $value)->first();

        if (! $row) {
            // Self-Healing drift tracking: Bloom filter had a false positive collision
            Log::warning('[upe:engine] self-healing drift / false positive detected', [
                'table' => $this->table,
                'field' => $this->field,
                'value' => $valStr,
                'description' => 'Bloom filter claimed element is present, but it was not found in the database.'
            ]);
            return null;
        }

        return (array) $row;
    }

    /**
     * Update: Updates record in database and adds the new value to the Bloom filter.
     */
    public function update(mixed $oldValue, array $attributes): bool
    {
        $newValue = $attributes[$this->field] ?? null;

        // Update database row
        $updated = DB::table($this->table)->where($this->field, $oldValue)->update($attributes);

        if ($updated && $newValue !== null && (string) $newValue !== (string) $oldValue) {
            // Write new value to Bloom Filter
            $this->driver->add($this->bloomKey, (string) $newValue);

            Log::info('[upe:engine] write-through update completed', [
                'table' => $this->table,
                'field' => $this->field,
                'old_value' => $oldValue,
                'new_value' => $newValue
            ]);
        }

        return (bool) $updated;
    }

    /**
     * Delete: Deletes record from database. Note that Bloom filter bits cannot be cleared.
     */
    public function delete(mixed $value): bool
    {
        $deleted = DB::table($this->table)->where($this->field, $value)->delete();

        if ($deleted) {
            Log::info('[upe:engine] record deleted from database (Bloom filter bits remain flipped)', [
                'table' => $this->table,
                'field' => $this->field,
                'value' => $value
            ]);
        }

        return (bool) $deleted;
    }

    /**
     * Get the active Bloom Filter driver instance.
     */
    public function getDriver(): BloomDriverInterface
    {
        return $this->driver;
    }

    /**
     * Verify that target column has a UNIQUE or INDEX key.
     */
    protected function verifyIndex(): void
    {
        try {
            if (! Schema::hasTable($this->table)) {
                Log::warning('[upe:engine] target table does not exist', ['table' => $this->table]);
                return;
            }

            $indexes = Schema::getIndexes($this->table);
            $isIndexed = false;

            foreach ($indexes as $index) {
                if (in_array($this->field, $index['columns'])) {
                    $isIndexed = true;
                    break;
                }
            }

            if (! $isIndexed) {
                Log::warning('[upe:engine] target column lacks UNIQUE or INDEX key. Query performance will degrade under collisions.', [
                    'table' => $this->table,
                    'field' => $this->field
                ]);
            }
        } catch (\Throwable $e) {
            Log::debug('[upe:engine] index verification skipped or unsupported by db schema tool', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
