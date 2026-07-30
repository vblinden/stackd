<?php

namespace App\Support;

class LaunchAgentManager
{
    public function __construct(
        private readonly StackdPaths $paths,
        private readonly ProcessManager $processes,
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
    }

    public function remove(string $service, string $name): void
    {
        $data = $this->readAutostart();
        $key = "{$service}:{$name}";

        $data['instances'] = array_values(array_filter(
            $data['instances'],
            fn (string $item) => $item !== $key,
        ));

        $this->writeAutostart($data);
        $this->syncLaunchAgent();
    }

    public function syncLaunchAgent(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $stackd = realpath(__DIR__.'/../../stackd')
            ?: realpath(__DIR__.'/../../application')
            ?: 'stackd';
        $plistPath = $this->paths->launchAgentPlist('com.stackd.autostart');
        $logDir = $this->paths->home().'/logs';

        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $instances = $this->list();
        $commands = [];

        foreach ($instances as $entry) {
            [$service, $name] = explode(':', $entry, 2);
            $commands[] = "{$stackd} start {$service} {$name}";
        }

        $script = "#!/bin/bash\n".implode("\n", $commands)."\n";
        $scriptPath = $this->paths->home().'/autostart.sh';
        file_put_contents($scriptPath, $script);
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

    private function buildPlist(string $scriptPath, string $logDir): string
    {
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
    <key>RunAtLoad</key>
    <true/>
    <key>StandardOutPath</key>
    <string>{$logDir}/autostart.log</string>
    <key>StandardErrorPath</key>
    <string>{$logDir}/autostart.error.log</string>
</dict>
</plist>
XML;
    }
}
