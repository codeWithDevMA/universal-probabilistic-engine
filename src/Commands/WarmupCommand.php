<?php

namespace Ghazali\UniversalProbabilisticEngine\Commands;

use Illuminate\Console\Command;
use Ghazali\UniversalProbabilisticEngine\BloomManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class WarmupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'upe:warmup {table : The target database table}
                            {field : The target column/field to hash}
                            {--clear : Clear the existing Bloom filter before warming up}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm up the Bloom filter by chunking through an existing table and hashing records.';

    /**
     * Execute the console command.
     */
    public function handle(BloomManager $manager): int
    {
        $table = $this->argument('table');
        $field = $this->argument('field');
        $clear = $this->option('clear');

        if (! Schema::hasTable($table)) {
            $this->error("Database table '{$table}' does not exist.");
            return self::FAILURE;
        }

        if (! Schema::hasColumn($table, $field)) {
            $this->error("Column '{$field}' does not exist on table '{$table}'.");
            return self::FAILURE;
        }

        $bloomKey = "{$table}:{$field}";
        $driver = $manager->driver();

        if ($clear) {
            $this->info("Clearing existing Bloom filter for: {$bloomKey}");
            $driver->clear($bloomKey);
        }

        $totalCount = DB::table($table)->count();
        if ($totalCount === 0) {
            $this->warn("Table '{$table}' contains no records. Warmup skipped.");
            return self::SUCCESS;
        }

        $this->info("Starting Bloom filter warmup for [{$bloomKey}] ({$totalCount} records)...");

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        // Chunk through records to optimize memory usage
        DB::table($table)->select($field)->chunkById(1000, function ($records) use ($driver, $bloomKey, $field, $bar) {
            $values = [];
            foreach ($records as $record) {
                $val = $record->$field;
                if ($val !== null && $val !== '') {
                    $values[] = (string) $val;
                }
            }

            if (! empty($values)) {
                $driver->warmup($bloomKey, $values);
            }

            $bar->advance(count($records));
        }, 'id'); // Enforces chunkById by primary key

        $bar->finish();
        $this->newLine(2);
        $this->info("Warmup complete for [{$bloomKey}]!");

        Log::info('[upe:warmup] command executed successfully', [
            'table' => $table,
            'field' => $field,
            'records_processed' => $totalCount,
            'cleared_first' => $clear
        ]);

        return self::SUCCESS;
    }
}
