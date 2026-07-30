<?php

namespace App\Support;

class LaravelProjectDetector
{
    public function isLaravelProject(?string $path = null): bool
    {
        $path = $path ?? getcwd();

        return file_exists($path.'/artisan') && file_exists($path.'/.env');
    }

    public function envPath(?string $path = null): string
    {
        return ($path ?? getcwd()).'/.env';
    }

    public function detectNeededServices(?string $envPath = null): array
    {
        if ($envPath === null) {
            if (! $this->isLaravelProject()) {
                return config('stackd.services');
            }

            $envPath = $this->envPath();
        }

        if (! file_exists($envPath)) {
            return config('stackd.services');
        }

        $contents = file_get_contents($envPath);
        $services = [];

        $map = [
            'DB_CONNECTION=mysql' => 'mysql',
            'DB_CONNECTION=mariadb' => 'mariadb',
            'DB_CONNECTION=pgsql' => 'postgresql',
            'REDIS_HOST=' => 'valkey',
            'CACHE_STORE=redis' => 'valkey',
            'SESSION_DRIVER=redis' => 'valkey',
            'QUEUE_CONNECTION=redis' => 'valkey',
            'MAIL_HOST=' => 'mailpit',
            'MAIL_MAILER=smtp' => 'mailpit',
            'SCOUT_DRIVER=meilisearch' => 'meilisearch',
            'MEILISEARCH_HOST=' => 'meilisearch',
            'AWS_ENDPOINT=' => 'minio',
        ];

        foreach ($map as $needle => $service) {
            if (str_contains($contents, $needle) && ! in_array($service, $services, true)) {
                $services[] = $service;
            }
        }

        return $services !== [] ? $services : ['mysql', 'valkey', 'mailpit'];
    }
}
