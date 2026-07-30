<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class BinaryDownloader
{
    public function __construct(
        private readonly StackdPaths $paths,
        private readonly ProcessManager $processes,
    ) {}

    public function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    public function download(string $url, string $destination): void
    {
        $this->ensureDirectory(dirname($destination));

        $process = new Process([
            'curl', '-fL', '--retry', '3', '--retry-delay', '2',
            '-o', $destination, $url,
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Failed to download {$url}: ".$process->getErrorOutput());
        }
    }

    public function extractTarGz(string $archive, string $destination): void
    {
        $this->ensureDirectory($destination);
        $this->processes->runOrFail(['tar', '-xzf', $archive, '-C', $destination]);
    }

    public function extractZip(string $archive, string $destination): void
    {
        $this->ensureDirectory($destination);
        $this->processes->runOrFail(['unzip', '-o', $archive, '-d', $destination]);
    }

    public function makeExecutable(string $path): void
    {
        if (! file_exists($path)) {
            throw new RuntimeException("Binary not found at {$path}");
        }

        chmod($path, 0755);
    }

    public function architecture(): string
    {
        $arch = php_uname('m');

        return match ($arch) {
            'arm64' => 'arm64',
            'x86_64' => 'x86_64',
            default => $arch,
        };
    }

    public function machineArch(): string
    {
        return $this->architecture() === 'arm64' ? 'arm64' : 'x86_64';
    }

    public function binaryPath(string $service, string $filename): string
    {
        $dir = $this->paths->binary($service);

        return $dir.'/'.$filename;
    }

    public function resolveBinary(string $service, string $filename, ?callable $installer = null): string
    {
        $path = $this->binaryPath($service, $filename);

        if (is_executable($path)) {
            return $path;
        }

        if ($installer !== null) {
            $installer($path);

            if (is_executable($path)) {
                return $path;
            }
        }

        throw new RuntimeException("Unable to resolve binary for {$service}.");
    }

    public function findFileRecursive(string $directory, string $filename): ?string
    {
        if (! is_dir($directory)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $filename) {
                return $file->getPathname();
            }
        }

        return null;
    }

    public function installFromTarball(string $url, string $destination, string $archiveName): string
    {
        $this->ensureDirectory($destination);

        $archive = $destination.'/'.$archiveName;

        if (! file_exists($archive)) {
            $this->download($url, $archive);
        }

        $this->extractTarGz($archive, $destination);

        if (file_exists($archive)) {
            unlink($archive);
        }

        $entries = array_values(array_diff(scandir($destination) ?: [], ['.', '..']));

        if (count($entries) === 1 && is_dir($destination.'/'.$entries[0])) {
            return $destination.'/'.$entries[0];
        }

        return $destination;
    }

    public function compile(string $sourceDirectory, string $binaryName, string $outputPath): void
    {
        $this->processes->runOrFail(['make', '-C', $sourceDirectory], timeout: 900);

        $built = $this->findFileRecursive($sourceDirectory, $binaryName);

        if ($built === null) {
            throw new RuntimeException("Compiled binary [{$binaryName}] was not found in {$sourceDirectory}.");
        }

        $this->ensureDirectory(dirname($outputPath));
        copy($built, $outputPath);
        $this->makeExecutable($outputPath);
    }
}
