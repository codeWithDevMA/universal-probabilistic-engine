<?php

namespace Ghazali\UniversalProbabilisticEngine\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Ghazali\UniversalProbabilisticEngine\BloomManager;
use Illuminate\Support\Facades\Log;

class UpeShieldedEloquentBuilder extends Builder
{
    /**
     * Execute the query and get the first result.
     *
     * @param  array|string  $columns
     * @return \Illuminate\Database\Eloquent\Model|object|static|null
     */
    public function first($columns = ['*'])
    {
        if ($this->shouldRejectQuery()) {
            return null;
        }

        return parent::first($columns);
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array|string  $columns
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function get($columns = ['*'])
    {
        if ($this->shouldRejectQuery()) {
            return $this->model->newCollection();
        }

        return parent::get($columns);
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * @param  string  $columns
     * @return int
     */
    public function count($columns = '*')
    {
        if ($this->shouldRejectQuery()) {
            return 0;
        }

        return parent::count($columns);
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * @return bool
     */
    public function exists()
    {
        if ($this->shouldRejectQuery()) {
            return false;
        }

        return parent::exists();
    }

    /**
     * Determine if the query should be fast-rejected based on UPE Bloom Filter.
     *
     * Returns true  → query is rejected instantly (O(1)), DB is never hit.
     * Returns false → query proceeds normally to the database.
     */
    protected function shouldRejectQuery(): bool
    {
        $wheres = $this->query->wheres;

        // SAFETY GUARD — OR logic: Bloom filters only work safely with AND conditions.
        // If the query uses OR (e.g. WHERE email = ? OR name = ?), a false-positive
        // from the filter on one column could incorrectly suppress the whole query.
        // Solution: skip fast-rejection entirely and let the DB handle it.
        foreach ($wheres as $where) {
            if (isset($where['boolean']) && strtolower($where['boolean']) === 'or') {
                return false; // ← Not rejected — go straight to DB
            }
        }

        // GUARD — No shielded columns on this model:
        // If the model does not use the ShieldsWithUpe trait, OR the model defines
        // $upeShieldedColumns = [] (empty), there is nothing to check in the filter.
        // Skip the Bloom filter entirely and let the query run normally against the DB.
        $shieldedColumns = method_exists($this->model, 'getUpeShieldedColumns')
            ? $this->model->getUpeShieldedColumns()
            : [];

        if (empty($shieldedColumns)) {
            return false; // ← Not rejected — model has no Bloom-protected columns
        }

        $table = $this->model->getTable();

        foreach ($wheres as $where) {
            if (
                isset($where['type']) && $where['type'] === 'Basic' &&
                isset($where['operator']) && $where['operator'] === '='
            ) {
                $column = $where['column'] ?? '';
                if (str_contains($column, '.')) {
                    // Strip table prefix e.g. "users.email" → "email"
                    $column = explode('.', $column)[1];
                }

                // GUARD — WHERE column is NOT in $upeShieldedColumns:
                // Only shielded columns are tracked in the Bloom filter.
                // If this WHERE targets a non-shielded column (e.g. WHERE id = 1,
                // WHERE name = 'foo'), the filter has no data for it.
                // Skip silently and let the query reach the DB as normal.
                if (in_array($column, $shieldedColumns)) {

                    // The WHERE column IS shielded — consult the Bloom filter.
                    $value = $where['value'] ?? null;
                    if ($value !== null && $value !== '') {
                        $bloomKey = "{$table}:{$column}";

                        try {
                            $driver = app(BloomManager::class)->driver();

                            if (!$driver->mightContain($bloomKey, (string) $value)) {
                                // DEFINITE MISS — the Bloom filter guarantees this value
                                // was NEVER inserted. The row cannot exist in the DB.
                                // Reject the query immediately — no DB round-trip needed.
                                Log::info("[upe:shield:{$table}] O(1) fast-rejection on column '{$column}'", [
                                    'column' => $column,
                                    'value'  => $value,
                                ]);
                                return true; // ← Rejected — DB is never hit
                            }

                            // POSSIBLE HIT — value might exist (false positives are possible).
                            // Fall through and let the DB confirm.
                        } catch (\Throwable $e) {
                            // Bloom filter lookup failed (DB issue, Redis down, etc.).
                            // Degrade gracefully — do NOT reject. Always fall back to DB.
                            Log::warning("[upe:shield:{$table}] Bloom Filter lookup failed, falling back to DB", [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
                // Column not shielded → no filter check → loop continues to next WHERE
            }
        }

        // No WHERE clause matched a shielded column with a definite miss.
        // Allow the query to run against the DB normally.
        return false; // ← Not rejected — go straight to DB
    }
}
