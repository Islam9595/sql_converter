<?php

namespace ie\sqlconvertertomigration;

use ie\sqlconvertertomigration\Console\InstallSQLConverterPackage;
use Illuminate\Support\ServiceProvider;

class SqlConverterToMigrationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallSQLConverterPackage::class,
            ]);
        }
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
