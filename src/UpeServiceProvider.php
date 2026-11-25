<?php

namespace Ghazali\UniversalProbabilisticEngine;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Ghazali\UniversalProbabilisticEngine\Commands\WarmupCommand;
use Ghazali\UniversalProbabilisticEngine\Commands\InstallCommand;

class UpeServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package using Spatie's fluent Package API.
     * This is the only method you need — registration, booting,
     * publishing, and command wiring are all handled automatically.
     */
    public function configurePackage(Package $package): void
    {
        $package
            ->name('upe')
            ->hasConfigFile('upe')            // publishes config/upe.php
            ->hasMigrations([                 // publishes stubs from database/migrations/
                'create_bloom_filters_table',
                'create_cuckoo_filters_table',
            ])
            ->hasCommands([
                WarmupCommand::class,
                InstallCommand::class,
            ]);
    }

    /**
     * Register the BloomManager singleton in addition to the
     * standard package registration done by PackageServiceProvider.
     */
    public function packageRegistered(): void
    {
        $this->app->singleton(BloomManager::class, function ($app) {
            return new BloomManager($app);
        });
    }
}
