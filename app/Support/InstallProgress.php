<?php

namespace App\Support;

use function Laravel\Prompts\spin;

class InstallProgress
{
    public function __construct(
        private readonly bool $enabled = true,
    ) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function task(string $message, callable $callback): mixed
    {
        if (! $this->enabled || ! $this->supportsInteractive()) {
            return $callback();
        }

        return spin($callback, $message);
    }

    /**
     * @template TReturn
     *
     * @param  callable(callable(int, ?int): void): TReturn  $callback
     * @return TReturn
     */
    public function download(string $label, callable $callback): mixed
    {
        if (! $this->enabled || ! $this->supportsInteractive()) {
            return $callback(static fn () => null);
        }

        $lastDrawn = '';

        $result = $callback(function (int $percent, ?int $bytes = null) use ($label, &$lastDrawn): void {
            $size = $bytes !== null ? ' · '.$this->formatBytes($bytes) : '';
            $line = sprintf(
                "\r\033[K\033[36m  ⠿\033[0m Downloading %s... %d%%%s",
                $label,
                max(0, min(100, $percent)),
                $size,
            );

            if ($line === $lastDrawn) {
                return;
            }

            $lastDrawn = $line;
            fwrite(STDERR, $line);
        });

        if ($lastDrawn !== '') {
            fwrite(STDERR, "\r\033[K\033[32m  ✓\033[0m Downloaded {$label}\n");
        }

        return $result;
    }

    public function extracting(string $label, callable $callback): mixed
    {
        return $this->task("Extracting {$label}...", $callback);
    }

    public function compiling(string $label, callable $callback): mixed
    {
        return $this->task("Compiling {$label} (this may take a few minutes)...", $callback);
    }

    public function configuring(string $label, callable $callback): mixed
    {
        return $this->task("Configuring {$label}...", $callback);
    }

    public function installing(string $label, callable $callback): mixed
    {
        return $this->task("Installing {$label}...", $callback);
    }

    public function starting(string $label, callable $callback): mixed
    {
        return $this->task("Starting {$label}...", $callback);
    }

    public function provisioning(string $label, callable $callback): mixed
    {
        return $this->task("Provisioning {$label}...", $callback);
    }

    private function supportsInteractive(): bool
    {
        return stream_isatty(STDERR) || stream_isatty(STDOUT);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $size, $units[$unit]);
    }
}
