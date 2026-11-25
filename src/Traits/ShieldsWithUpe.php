<?php

namespace Ghazali\UniversalProbabilisticEngine\Traits;

use Ghazali\UniversalProbabilisticEngine\BloomManager;
use Ghazali\UniversalProbabilisticEngine\Eloquent\UpeShieldedEloquentBuilder;
use Illuminate\Support\Facades\Log;

trait ShieldsWithUpe
{
    /**
     * Prevents duplicate increments on model creation.
     */
    public bool $_upe_created = false;

    /**
     * Boot the trait to sync writes and deletes with the Bloom filter.
     */
    public static function bootShieldsWithUpe(): void
    {
        $normalize = function (string $column, mixed $value): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            $strValue = (string) $value;

            if (class_exists('App\Services\FieldEncryptionService')) {
                $svc = app('App\Services\FieldEncryptionService');
                if ($svc->isEnabled() && in_array($column, $svc::encryptableFields(), true)) {
                    if ($column === 'email' && str_contains($strValue, '@')) {
                        return $svc->encryptEmail($strValue);
                    }
                }
            }

            return $strValue;
        };

        // 1. Sync on Save (Create/Update)
        static::saved(function ($model) use ($normalize) {
            $table = $model->getTable();
            try {
                $columns = $model->getUpeShieldedColumns();
                if (empty($columns)) {
                    return;
                }

                $driver = app(BloomManager::class)->driver();
                $isCreate = $model->wasRecentlyCreated && empty($model->_upe_created);

                if ($isCreate) {
                    $model->_upe_created = true;
                    foreach ($columns as $column) {
                        $value = $model->getAttribute($column);
                        $normalized = $normalize($column, $value);
                        if ($normalized !== null && $normalized !== '') {
                            $driver->add("{$table}:{$column}", $normalized);
                        }
                    }
                } else {
                    foreach ($columns as $column) {
                        if ($model->isDirty($column)) {
                            // Sync changes: remove old value, add new value
                            $oldValue = $model->getOriginal($column);
                            $normalizedOld = $normalize($column, $oldValue);
                            if ($normalizedOld !== null && $normalizedOld !== '') {
                                $driver->remove("{$table}:{$column}", $normalizedOld);
                            }
                            $newValue = $model->getAttribute($column);
                            $normalizedNew = $normalize($column, $newValue);
                            if ($normalizedNew !== null && $normalizedNew !== '') {
                                $driver->add("{$table}:{$column}", $normalizedNew);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error("[upe:shield:{$table}] Write-through sync failed", [
                    'error' => $e->getMessage(),
                    'id'    => $model->getKey(),
                ]);
            }
        });

        // 2. Decrement counters on Delete
        static::deleted(function ($model) use ($normalize) {
            $table = $model->getTable();
            try {
                $columns = $model->getUpeShieldedColumns();
                if (empty($columns)) {
                    return;
                }

                $driver = app(BloomManager::class)->driver();
                $attrs = $model->getAttributes() ?: $model->getRawOriginal();

                foreach ($columns as $column) {
                    $value = $attrs[$column] ?? null;
                    $normalized = $normalize($column, $value);
                    if ($normalized !== null && $normalized !== '') {
                        $driver->remove("{$table}:{$column}", $normalized);
                    }
                }
            } catch (\Throwable $e) {
                Log::error("[upe:shield:{$table}] Decrement failed on delete", [
                    'error' => $e->getMessage(),
                    'id'    => $model->getKey(),
                ]);
            }
        });
    }

    /**
     * Get the shielded columns for the Bloom filter.
     */
    public function getUpeShieldedColumns(): array
    {
        return $this->upeShieldedColumns ?? [];
    }

    /**
     * Intercept query execution with the generic UpeShieldedEloquentBuilder.
     */
    public function newEloquentBuilder($query)
    {
        return new UpeShieldedEloquentBuilder($query);
    }
}
