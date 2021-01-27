<?php

namespace ie\sqlconvertertomigration\Console;

use Illuminate\Console\Command;

class InstallSQLConverterPackage extends Command
{
    protected $signature = 'SQLConverterPackage:install';

    protected $description = 'Install the SQLConverterPackage';

    public function handle()
    {
        $this->info('Installing SQLConverterPackage...');

        $this->info('Publishing configuration...');

        $this->call('vendor:publish', [
            '--provider' => "ie\sql_converter_migration\SqlConverterToMigrationServiceProvider",
            '--tag' => "config"
        ]);
        $this->info('Installed SQLConverterPackage');
    }
}