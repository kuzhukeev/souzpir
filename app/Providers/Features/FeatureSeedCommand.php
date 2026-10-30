<?php

namespace App\Providers\Features;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use LogicException;

class FeatureSeedCommand extends Command
{
    protected $signature = 'features:seed {feature? : Registered feature ID} {--all : Explicitly seed all registered features}';

    protected $description = 'Run registered feature seeders in local/testing only';

    public function handle(FeatureRegistry $registry): int
    {
        if (! $this->laravel->environment('local', 'testing')) {
            $this->error('Feature seeders are available only in local/testing.');

            return self::FAILURE;
        }

        $feature = $this->argument('feature');

        if (($feature === null) === (! $this->option('all'))) {
            $this->error('Choose one feature ID or --all.');

            return self::FAILURE;
        }

        try {
            $seeders = $registry->seeders($feature);
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($seeders as $class) {
            /** @var Seeder $seeder */
            $seeder = $this->laravel->make($class);
            $seeder->setContainer($this->laravel)->setCommand($this);
            $seeder->__invoke();
        }

        $this->info('Registered feature seeders completed.');

        return self::SUCCESS;
    }
}
