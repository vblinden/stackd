<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class BinaryDownloader
{
    public function __construct(
        private readonly StackdPaths $paths,
        private readonly ProcessManager $processes,
        private readonly InstallProgress $progress,
    ) {}

    public function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    public function download(string $url, string $destination, ?string $label = null): void
    {
        $this->ensureDirectory(dirname($destination));
        $label ??= $this->labelFromUrl($url);

        $this->progress->download($label, function (callable $onProgress) use ($url, $destination): void {
            $tmp = $destination.'.partial';

            if (file_exists($tmp)) {
                unlink($tmp);
            }

            if (function_exists('curl_init')) {
                $this->downloadWithCurl($url, $tmp, $onProgress);
            } else {
                $this->downloadWithCli($url, $tmp, $onProgress);
            }

            $onProgress(100, (int) filesize($tmp));
            rename($tmp, $destination);
        });
    }

    /**
     * @param  callable(int, ?int): void  $onProgress
     */
    private function downloadWithCurl(string $url, string $tmp, callable $onProgress): void
    {
        $handle = fopen($tmp, 'w');

        if ($handle === false) {
            throw new RuntimeException("Unable to write download to {$tmp}");
        }

        $ch = curl_init($url);

        if ($ch === false) {
            fclose($handle);

            throw new RuntimeException("Unable to start download from {$url}");
        }

        $progressCallback = function ($resource, float $downloadTotal, float $downloaded) use ($onProgress): int {
            if ($downloadTotal > 0) {
                $onProgress((int) round(($downloaded / $downloadTotal) * 100), (int) $downloaded);
            } elseif ($downloaded > 0) {
                $onProgress(0, (int) $downloaded);
            }

            return 0;
        };

        $options = [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_NOPROGRESS => false,
        ];

        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            $options[CURLOPT_XFERINFOFUNCTION] = $progressCallback;
        } else {
            $options[CURLOPT_PROGRESSFUNCTION] = $progressCallback;
        }

        curl_setopt_array($ch, $options);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($handle);

        if ($ok === false) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }

            throw new RuntimeException("Failed to download {$url}".($error !== '' ? ": {$error}" : ''));
        }
    }

    /**
     * @param  callable(int, ?int): void  $onProgress
     */
    private function downloadWithCli(string $url, string $tmp, callable $onProgress): void
    {
        $onProgress(0, null);

        $process = new Process([
            'curl',
            '-fL',
            '--retry', '3',
            '--connect-timeout', '15',
            '-o', $tmp,
            $url,
        ]);
        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($tmp)) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }

            throw new RuntimeException("Failed to download {$url}: ".$process->getErrorOutput());
        }
    }

    public function extractTarGz(string $archive, string $destination, ?string $label = null): void
    {
        $this->ensureDirectory($destination);
        $label ??= basename($archive);

        $this->progress->extracting($label, function () use ($archive, $destination): void {
            $this->processes->runOrFail(['tar', '-xzf', $archive, '-C', $destination]);
        });
    }

    public function extractZip(string $archive, string $destination, ?string $label = null): void
    {
        $this->ensureDirectory($destination);
        $label ??= basename($archive);

        $this->progress->extracting($label, function () use ($archive, $destination): void {
            $this->processes->runOrFail(['unzip', '-o', $archive, '-d', $destination]);
        });
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

    public function downloadExecutable(string $url, string $destination, ?string $label = null): void
    {
        $this->download($url, $destination, $label);
        $this->makeExecutable($destination);
    }

    public function installFromTarball(string $url, string $destination, string $archiveName, ?string $label = null): string
    {
        $this->ensureDirectory($destination);
        $label ??= $this->labelFromUrl($url);

        $archive = $destination.'/'.$archiveName;

        if (! file_exists($archive)) {
            $this->download($url, $archive, $label);
        }

        $this->extractTarGz($archive, $destination, $label);

        if (file_exists($archive)) {
            unlink($archive);
        }

        $entries = array_values(array_diff(scandir($destination) ?: [], ['.', '..']));

        if (count($entries) === 1 && is_dir($destination.'/'.$entries[0])) {
            return $destination.'/'.$entries[0];
        }

        return $destination;
    }

    public function compile(string $sourceDirectory, string $binaryName, string $outputPath, ?string $label = null): void
    {
        $label ??= $binaryName;

        $this->progress->compiling($label, function () use ($sourceDirectory, $binaryName, $outputPath): void {
            $this->processes->runOrFail(['make', '-C', $sourceDirectory], timeout: 900);

            $built = $this->findFileRecursive($sourceDirectory, $binaryName);

            if ($built === null) {
                throw new RuntimeException("Compiled binary [{$binaryName}] was not found in {$sourceDirectory}.");
            }

            $this->ensureDirectory(dirname($outputPath));
            copy($built, $outputPath);
            $this->makeExecutable($outputPath);
        });
    }

    public function darwinTriple(): string
    {
        return $this->architecture() === 'arm64'
            ? 'aarch64-apple-darwin'
            : 'x86_64-apple-darwin';
    }

    public function progress(): InstallProgress
    {
        return $this->progress;
    }

    private function labelFromUrl(string $url): string
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return $name !== '' ? $name : 'package';
    }
}
