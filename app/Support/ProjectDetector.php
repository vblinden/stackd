<?php

namespace App\Support;

class ProjectDetector
{
    public const FRAMEWORK_LARAVEL = 'laravel';

    public const FRAMEWORK_NEXTJS = 'nextjs';

    private const DATABASE_SERVICES = ['mysql', 'mariadb', 'postgresql'];

    public function __construct(
        private readonly ?InstanceRepository $instances = null,
    ) {}

    public function framework(?string $path = null): ?string
    {
        if ($this->isLaravelProject($path)) {
            return self::FRAMEWORK_LARAVEL;
        }

        if ($this->isNextJsProject($path)) {
            return self::FRAMEWORK_NEXTJS;
        }

        return null;
    }

    public function isLaravelProject(?string $path = null): bool
    {
        $path = $path ?? getcwd();

        return file_exists($path.'/artisan') && file_exists($path.'/.env');
    }

    public function isNextJsProject(?string $path = null): bool
    {
        $path = $path ?? getcwd();
        $packagePath = $path.'/package.json';

        if (! file_exists($packagePath)) {
            return false;
        }

        $package = json_decode((string) file_get_contents($packagePath), true);

        if (! is_array($package)) {
            return false;
        }

        $dependencies = array_merge(
            is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [],
            is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : [],
        );

        return array_key_exists('next', $dependencies);
    }

    public function canWriteEnv(?string $path = null): bool
    {
        return $this->framework($path) !== null;
    }

    public function envPath(?string $path = null): string
    {
        $path = $path ?? getcwd();

        if ($this->isNextJsProject($path) && ! file_exists($path.'/.env') && file_exists($path.'/.env.local')) {
            return $path.'/.env.local';
        }

        return $path.'/.env';
    }

    /**
     * Services whose .env keys should be printed / overwritten.
     *
     * Uses every installed stackd instance (mailpit, valkey, …). At most one
     * database service is included so DB_* / DATABASE_URL keys do not clash —
     * matching DB_CONNECTION or DATABASE_URL when possible, otherwise the first
     * installed DB.
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
        if ($envPath !== null) {
            return file_exists($envPath) ? (string) file_get_contents($envPath) : '';
        }

        $root = getcwd() ?: '';
        $chunks = [];

        foreach (['.env', '.env.local'] as $name) {
            $file = $root.'/'.$name;

            if (file_exists($file)) {
                $chunks[] = (string) file_get_contents($file);
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * @param  list<string>  $installedDatabases
     * @return 'mysql'|'mariadb'|'postgresql'
     */
    public function pickDatabaseService(string $contents, array $installedDatabases): string
    {
        if ($contents !== '' && preg_match('/^DB_CONNECTION=(.*)$/m', $contents, $matches) === 1) {
            $mapped = $this->mapConnectionName(strtolower(trim($matches[1], " \t\"'")));

            if ($mapped !== null && in_array($mapped, $installedDatabases, true)) {
                return $mapped;
            }
        }

        if ($contents !== '' && preg_match('/^(?:DATABASE_URL|POSTGRES_URL)=(.*)$/m', $contents, $matches) === 1) {
            $url = trim($matches[1], " \t\"'");
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $mapped = $this->mapConnectionName($scheme);

            if ($scheme === 'mysql' && ! in_array('mysql', $installedDatabases, true) && in_array('mariadb', $installedDatabases, true)) {
                $mapped = 'mariadb';
            }

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

    /**
     * @return 'mysql'|'mariadb'|'postgresql'|null
     */
    private function mapConnectionName(string $name): ?string
    {
        return match ($name) {
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'pgsql', 'postgresql', 'postgres' => 'postgresql',
            default => null,
        };
    }
}
