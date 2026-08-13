<?php

namespace App\Support;

use RuntimeException;

class LaunchAgentManager
{
    public function __construct(
        private readonly StackdPaths $paths,
        private readonly ProcessManager $processes,
        private readonly InstanceRepository $repository,
        private readonly DockerEngine $docker,
    ) {}

    public function isEnabled(): bool
    {
        return file_exists($this->paths->autostart());
    }

    public function enable(): void
    {
        $this->paths->ensureHome();

        if (! file_exists($this->paths->autostart())) {
            file_put_contents($this->paths->autostart(), json_encode([
                'enabled' => true,
                'instances' => [],
            ], JSON_PRETTY_PRINT)."\n");
        }

        $this->syncLaunchAgent();
    }

    public function disable(): void
    {
        foreach ($this->list() as $entry) {
            if (! str_contains($entry, ':')) {
                continue;
            }

            [$service, $name] = explode(':', $entry, 2);
            $this->syncDockerRestart($service, $name, 'no');
        }

        $plist = $this->paths->launchAgentPlist('com.stackd.autostart');

        if (file_exists($plist)) {
            $this->processes->run(['launchctl', 'unload', $plist]);
            unlink($plist);
        }

        if (file_exists($this->paths->autostart())) {
            unlink($this->paths->autostart());
        }
    }

    public function list(): array
    {
        if (! file_exists($this->paths->autostart())) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->paths->autostart()), true);

        return $data['instances'] ?? [];
    }

    public function add(string $service, string $name): void
    {
        $data = $this->readAutostart();
        $key = "{$service}:{$name}";

        if (! in_array($key, $data['instances'], true)) {
            $data['instances'][] = $key;
        }

        $this->writeAutostart($data);
        $this->syncLaunchAgent();
        $this->syncDockerRestart($service, $name, 'unless-stopped');
    }

    public function remove(string $service, string $name): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $data = $this->readAutostart();
        $key = "{$service}:{$name}";

        $data['instances'] = array_values(array_filter(
            $data['instances'],
            fn (string $item) => $item !== $key,
        ));

        $this->writeAutostart($data);
        $this->syncLaunchAgent();
        $this->syncDockerRestart($service, $name, 'no');
    }

    public function syncLaunchAgent(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $plistPath = $this->paths->launchAgentPlist('com.stackd.autostart');
        $logDir = $this->paths->home().'/logs';

        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $scriptPath = $this->paths->home().'/autostart.sh';
        file_put_contents($scriptPath, $this->buildAutostartScript());
        chmod($scriptPath, 0755);

        $plist = $this->buildPlist($scriptPath, $logDir);
        $launchAgentsDir = $this->paths->launchAgents();

        if (! is_dir($launchAgentsDir)) {
            mkdir($launchAgentsDir, 0755, true);
        }

        if (file_exists($plistPath)) {
            $this->processes->run(['launchctl', 'unload', $plistPath]);
        }

        file_put_contents($plistPath, $plist);
        $this->processes->run(['launchctl', 'load', $plistPath]);
    }

    public function buildAutostartScript(): string
    {
        $php = $this->resolvePhpBinary();
        $stackd = $this->resolveStackdBinary();
        $path = $this->launchdPath($php, $stackd);

        $lines = [
            '#!/bin/bash',
            'export PATH='.escapeshellarg($path),
            implode(' ', array_map('escapeshellarg', [$php, $stackd, 'autostart', 'run'])),
            '',
        ];

        return implode("\n", $lines);
    }

    public function resolvePhpBinary(): string
    {
        if (defined('PHP_BINARY') && PHP_BINARY !== '' && is_file(PHP_BINARY)) {
            return PHP_BINARY;
        }

        foreach (['/opt/homebrew/bin/php', '/usr/local/bin/php'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to resolve the PHP binary for the LaunchAgent.');
    }

    public function resolveStackdBinary(): string
    {
        $phar = \Phar::running(false);

        if (is_string($phar) && $phar !== '' && is_file($phar)) {
            return $phar;
        }

        foreach ($this->stackdCandidates() as $candidate) {
            if (str_contains($candidate, 'phar://')) {
                continue;
            }

            $resolved = realpath($candidate);

            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }

        throw new RuntimeException(
            'Unable to resolve the stackd binary for the LaunchAgent. Re-run from an installed stackd executable.',
        );
    }

    /**
     * @return list<string>
     */
    private function stackdCandidates(): array
    {
        $candidates = [];

        foreach ([
            $_SERVER['SCRIPT_FILENAME'] ?? null,
            $_SERVER['argv'][0] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        $candidates[] = __DIR__.'/../../stackd';
        $candidates[] = __DIR__.'/../../application';

        return $candidates;
    }

    private function launchdPath(string $php, string $stackd): string
    {
        $home = rtrim((string) (getenv('HOME') ?: ''), '/');

        $parts = array_filter([
            dirname($php),
            dirname($stackd),
            '/opt/homebrew/bin',
            '/usr/local/bin',
            $home !== '' ? $home.'/.config/composer/vendor/bin' : null,
            $home !== '' ? $home.'/.composer/vendor/bin' : null,
            '/usr/bin',
            '/bin',
            '/usr/sbin',
            '/sbin',
        ]);

        return implode(':', array_values(array_unique($parts)));
    }

    private function readAutostart(): array
    {
        $this->paths->ensureHome();

        if (! file_exists($this->paths->autostart())) {
            return ['enabled' => true, 'instances' => []];
        }

        return json_decode((string) file_get_contents($this->paths->autostart()), true) ?: [
            'enabled' => true,
            'instances' => [],
        ];
    }

    private function writeAutostart(array $data): void
    {
        $this->paths->ensureHome();
        $data['enabled'] = true;

        file_put_contents(
            $this->paths->autostart(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    public function buildPlist(string $scriptPath, string $logDir): string
    {
        $path = $this->escapePlist($this->launchdPath($this->resolvePhpBinary(), $this->resolveStackdBinary()));
        $home = $this->escapePlist(rtrim((string) (getenv('HOME') ?: ''), '/'));
        $scriptPath = $this->escapePlist($scriptPath);
        $stdout = $this->escapePlist($logDir.'/autostart.log');
        $stderr = $this->escapePlist($logDir.'/autostart.error.log');

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.stackd.autostart</string>
    <key>ProgramArguments</key>
    <array>
        <string>/bin/bash</string>
        <string>{$scriptPath}</string>
    </array>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>{$path}</string>
        <key>HOME</key>
        <string>{$home}</string>
    </dict>
    <key>RunAtLoad</key>
    <true/>
    <key>ProcessType</key>
    <string>Background</string>
    <key>LowPriorityIO</key>
    <true/>
    <key>Nice</key>
    <integer>10</integer>
    <key>StandardOutPath</key>
    <string>{$stdout}</string>
    <key>StandardErrorPath</key>
    <string>{$stderr}</string>
</dict>
</plist>
XML;
    }

    private function escapePlist(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function syncDockerRestart(string $service, string $name, string $policy): void
    {
        $instance = $this->repository->find($service, $name);

        if ($instance === null || ! $instance->isDocker()) {
            return;
        }

        try {
            $this->docker->setRestartPolicy($instance, $policy);
        } catch (\Throwable) {
            // Docker Desktop may be stopped; start-at-login still goes through stackd start.
        }
    }
}
