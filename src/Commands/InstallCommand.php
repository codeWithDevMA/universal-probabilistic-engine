<?php

namespace Ghazali\UniversalProbabilisticEngine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'upe:install {--driver= : Pre-specify the driver to install (mysql, postgresql, sqlite, redis)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install UPE, publish assets, and configure the driver for your database/cloud environment.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== Universal Probabilistic Engine (UPE) Installation ===');

        // ── 1. Publish Config and Migrations ───────────────────────────────────
        $this->info('Publishing config and migrations...');
        $this->call('vendor:publish', [
            '--provider' => 'Ghazali\UniversalProbabilisticEngine\UpeServiceProvider'
        ]);

        // ── 2. Determine driver choice ─────────────────────────────────────────
        $driver = $this->option('driver');

        if (! $driver) {
            $driver = $this->choice(
                'Which backend driver would you like UPE to use?',
                ['mysql', 'postgresql', 'sqlite', 'redis'],
                'mysql'
            );
        }

        $driver = strtolower($driver);
        if (! in_array($driver, ['mysql', 'postgresql', 'sqlite', 'redis'])) {
            $this->error("Invalid driver choice '{$driver}'. Please choose from: mysql, postgresql, sqlite, redis.");
            return self::FAILURE;
        }

        // mysql, postgresql, and sqlite all map to 'mysql' driver config internally (which is database-agnostic SQL)
        $configDriver = ($driver === 'redis') ? 'redis' : 'mysql';

        $this->info("Selected UPE Driver: {$driver} (config driver: {$configDriver})");

        // ── 3. Update .env File ───────────────────────────────────────────────
        $envPath = base_path('.env');
        if (File::exists($envPath)) {
            $this->info('Configuring your .env file...');
            $envContent = File::get($envPath);

            $key = 'UPE_DRIVER';
            $val = $configDriver;

            if (preg_match("/^{$key}=/m", $envContent)) {
                $envContent = preg_replace("/^{$key}=.*/m", "{$key}={$val}", $envContent);
            } else {
                $envContent .= "\n\n# UPE Settings\n{$key}={$val}\n";
            }

            File::put($envPath, $envContent);
            $this->info("[✓] Set UPE_DRIVER={$val} in your .env file.");
        } else {
            $this->warn('.env file not found. Please manually configure UPE_DRIVER=' . $configDriver);
        }

        // ── 4. Verify Database connection matches ──────────────────────────────
        $currentDbConnection = config('database.default');
        $this->info("Your current default database connection is: {$currentDbConnection}");

        if ($driver !== 'redis' && $driver !== $currentDbConnection) {
            $this->warn("Note: You selected driver '{$driver}' but your default database connection is '{$currentDbConnection}'.");
            $this->warn("Make sure the migrations run on the same database target so your production cloud environment functions correctly.");
        }

        $this->info("\nUPE installed successfully! Run 'php artisan migrate' to set up the bloom filters table.\n");
        return self::SUCCESS;
    }
}
