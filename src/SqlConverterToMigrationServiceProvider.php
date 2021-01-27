<?php

namespace Ie\SQlConverterToMigration;

use Illuminate\Support\ServiceProvider;
use JohnDoe\BlogPackage\Console\InstallSQLConverterPackage;

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
