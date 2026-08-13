<?php

namespace App\Commands;

use App\Support\StackdConfig;
use LaravelZero\Framework\Commands\Command;

class RuntimeCommand extends Command
{
    protected $signature = 'runtime
                            {choice? : native or docker}';

    protected $description = 'Show or set the default instance runtime';

    public function handle(StackdConfig $config): int
    {
        try {
            $choice = $this->argument('choice');

            if ($choice === null || $choice === '') {
                $this->components->info('Runtime: '.$config->runtime());

                return self::SUCCESS;
            }

            $config->setRuntime((string) $choice);
            $this->components->info('Runtime set to '.$config->runtime().'. New instances will use this runtime.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
