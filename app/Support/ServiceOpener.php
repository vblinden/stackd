<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class ServiceOpener
{
    public function openUrl(string $url): void
    {
        $process = Process::fromShellCommandline('open '.escapeshellarg($url));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Failed to open URL: {$url}");
        }
    }

    public function openDatabase(
        string $driver,
        string $host,
        int $port,
        string $database,
        string $user,
        ?string $password = null,
        ?string $name = null,
    ): void {
        $connectionUrl = $this->buildConnectionUrl(
            driver: $driver,
            host: $host,
            port: $port,
            database: $database,
            user: $user,
            password: $password,
            name: $name,
        );

        if ($this->openWithTablePlus($connectionUrl)) {
            return;
        }

        throw new RuntimeException('TablePlus is not installed. Install it from https://tableplus.com or open manually.');
    }

    public function buildConnectionUrl(
        string $driver,
        string $host,
        int $port,
        string $database,
        string $user,
        ?string $password = null,
        ?string $name = null,
    ): string {
        $scheme = match ($driver) {
            'mysql', 'mariadb' => 'mysql',
            'postgresql' => 'postgresql',
            default => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };

        $auth = rawurlencode($user);

        if ($password !== null && $password !== '') {
            $auth .= ':'.rawurlencode($password);
        }

        $url = sprintf(
            '%s://%s@%s:%d/%s',
            $scheme,
            $auth,
            $host,
            $port,
            rawurlencode($database),
        );

        $params = array_filter([
            'env' => 'local',
            'name' => $name,
        ]);

        if ($params !== []) {
            $url .= '?'.http_build_query($params);
        }

        return $url;
    }

    private function openWithTablePlus(string $connectionUrl): bool
    {
        if (! $this->tablePlusInstalled()) {
            return false;
        }

        $process = Process::fromShellCommandline('open '.escapeshellarg($connectionUrl));
        $process->run();

        if ($process->isSuccessful()) {
            return true;
        }

        $process = Process::fromShellCommandline('open -a TablePlus '.escapeshellarg($connectionUrl));
        $process->run();

        return $process->isSuccessful();
    }

    private function tablePlusInstalled(): bool
    {
        return is_dir('/Applications/TablePlus.app')
            || $this->findInPath('tableplus') !== null;
    }

    private function findInPath(string $binary): ?string
    {
        $process = Process::fromShellCommandline('command -v '.escapeshellarg($binary));
        $process->run();

        $path = trim($process->getOutput());

        return $path !== '' ? $path : null;
    }
}
