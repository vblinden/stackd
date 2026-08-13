<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

class StackdConfig
{
    public const RUNTIME_NATIVE = 'native';

    public const RUNTIME_DOCKER = 'docker';

    public function __construct(
        private readonly StackdPaths $paths,
    ) {}

    public function runtime(): string
    {
        $runtime = $this->read()['runtime'] ?? self::RUNTIME_NATIVE;

        return $this->normalizeRuntime(is_string($runtime) ? $runtime : self::RUNTIME_NATIVE);
    }

    public function setRuntime(string $runtime): void
    {
        $data = $this->read();
        $data['runtime'] = $this->normalizeRuntime($runtime);
        $this->write($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $this->paths->ensureHome();
        $path = $this->paths->config();

        if (! file_exists($path)) {
            return ['runtime' => self::RUNTIME_NATIVE];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : ['runtime' => self::RUNTIME_NATIVE];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): void
    {
        $this->paths->ensureHome();
        $path = $this->paths->config();
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $contents = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        file_put_contents($temporary, $contents, LOCK_EX);

        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to update stackd config at {$path}.");
        }
    }

    private function normalizeRuntime(string $runtime): string
    {
        $runtime = strtolower(trim($runtime));

        if (! in_array($runtime, [self::RUNTIME_NATIVE, self::RUNTIME_DOCKER], true)) {
            throw new InvalidArgumentException('Runtime must be native or docker.');
        }

        return $runtime;
    }
}
