<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class HomebrewConflict
{
    /**
     * Homebrew formula prefixes that conflict with stackd services.
     *
     * @var array<string, list<string>>
     */
    private const CONFLICTS = [
        'mysql' => ['mysql'],
        'mariadb' => ['mariadb'],
        'postgresql' => ['postgresql'],
        'valkey' => ['valkey', 'redis'],
        'mailpit' => ['mailpit'],
        'meilisearch' => ['meilisearch'],
        'minio' => ['minio'],
    ];

    public function findBrew(): ?string
    {
        foreach (['/opt/homebrew/bin/brew', '/usr/local/bin/brew'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        $process = Process::fromShellCommandline('command -v brew');
        $process->run();
        $brew = trim($process->getOutput());

        return ($brew !== '' && is_executable($brew)) ? $brew : null;
    }

    /**
     * @return list<string>
     */
    public function installedConflicts(?string $serviceType = null): array
    {
        $brew = $this->findBrew();

        if ($brew === null) {
            return [];
        }

        $process = new Process([$brew, 'list', '--formula']);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $installed = preg_split('/\s+/', trim($process->getOutput())) ?: [];

        return $this->filterConflicts(array_values(array_filter($installed)), $serviceType);
    }

    /**
     * @param  list<string>  $installed
     * @return list<string>
     */
    public function filterConflicts(array $installed, ?string $serviceType = null): array
    {
        $prefixes = $serviceType === null
            ? array_values(array_unique(array_merge(...array_values(self::CONFLICTS))))
            : (self::CONFLICTS[$serviceType] ?? []);

        if ($prefixes === []) {
            return [];
        }

        $matches = [];

        foreach ($installed as $formula) {
            foreach ($prefixes as $prefix) {
                if ($formula === $prefix || str_starts_with($formula, $prefix.'@')) {
                    $matches[] = $formula;
                    break;
                }
            }
        }

        sort($matches);

        return array_values(array_unique($matches));
    }

    /**
     * Stop brew services and uninstall the given formulas.
     *
     * @param  list<string>  $formulas
     */
    public function uninstall(array $formulas): void
    {
        $brew = $this->findBrew();

        if ($brew === null) {
            throw new RuntimeException('Homebrew was not found.');
        }

        foreach ($formulas as $formula) {
            $stop = new Process([$brew, 'services', 'stop', $formula]);
            $stop->setTimeout(60);
            $stop->run();

            $uninstall = new Process([$brew, 'uninstall', '--force', $formula]);
            $uninstall->setTimeout(300);
            $uninstall->run();

            if (! $uninstall->isSuccessful()) {
                throw new RuntimeException(
                    trim($uninstall->getErrorOutput() ?: $uninstall->getOutput())
                    ?: "Failed to uninstall Homebrew formula [{$formula}]."
                );
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function conflictMap(): array
    {
        return self::CONFLICTS;
    }
}
