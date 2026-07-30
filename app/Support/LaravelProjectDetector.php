<?php

namespace App\Support;

class LaravelProjectDetector
{
    private const DATABASE_SERVICES = ['mysql', 'mariadb', 'postgresql'];

    public function __construct(
        private readonly ?InstanceRepository $instances = null,
    ) {}

    public function isLaravelProject(?string $path = null): bool
    {
        $path = $path ?? getcwd();

        return file_exists($path.'/artisan') && file_exists($path.'/.env');
    }

    public function envPath(?string $path = null): string
    {
        return ($path ?? getcwd()).'/.env';
    }

    /**
     * Services whose Laravel .env keys should be printed / overwritten.
     *
     * Uses every installed stackd instance (mailpit, valkey, …). At most one
     * database service is included so DB_* keys do not clash — matching
     * DB_CONNECTION when possible, otherwise the first installed DB.
     *
     * @return list<string>
     */
    public function detectNeededServices(?string $envPath = null): array
    {
        if ($this->instances === null) {
            return config('stackd.services');
        }

        $installed = [];

        foreach (config('stackd.services') as $type) {
            if ($this->instances->defaultForService($type) !== null) {
                $installed[] = $type;
            }
        }

        if ($installed === []) {
            return [];
        }

        $databases = array_values(array_intersect(self::DATABASE_SERVICES, $installed));
        $others = array_values(array_diff($installed, self::DATABASE_SERVICES));

        $services = $others;

        if ($databases !== []) {
            $contents = $this->readEnvContents($envPath);
            $database = $this->pickDatabaseService($contents, $databases);
            array_unshift($services, $database);
        }

        return $services;
    }

    private function readEnvContents(?string $envPath): string
    {
        if ($envPath === null) {
            if (! $this->isLaravelProject()) {
                return '';
            }

            $envPath = $this->envPath();
        }

        if (! file_exists($envPath)) {
            return '';
        }

        return (string) file_get_contents($envPath);
    }

    /**
     * @param  list<string>  $installedDatabases
     * @return 'mysql'|'mariadb'|'postgresql'
     */
    public function pickDatabaseService(string $contents, array $installedDatabases): string
    {
        if ($contents !== '' && preg_match('/^DB_CONNECTION=(.*)$/m', $contents, $matches) === 1) {
            $connection = strtolower(trim($matches[1], " \t\"'"));

            $mapped = match ($connection) {
                'mysql' => 'mysql',
                'mariadb' => 'mariadb',
                'pgsql', 'postgresql' => 'postgresql',
                default => null,
            };

            if ($mapped !== null && in_array($mapped, $installedDatabases, true)) {
                return $mapped;
            }
        }

        foreach (self::DATABASE_SERVICES as $service) {
            if (in_array($service, $installedDatabases, true)) {
                return $service;
            }
        }

        return $installedDatabases[0];
    }
}
