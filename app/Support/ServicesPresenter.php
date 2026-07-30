<?php

namespace App\Support;

use Symfony\Component\Console\Output\OutputInterface;

class ServicesPresenter
{
    /**
     * @param  array<int, array{service: string, name: string, running: bool, address: string, pid: string|null, credentials?: array<string, string>}>  $services
     */
    public function render(OutputInterface $output, array $services, bool $runningOnly = false): void
    {
        $output->writeln('');
        $output->writeln('  <fg=white;options=bold>Services</>');

        if ($services === []) {
            $output->writeln('  <fg=gray>No instances yet. Run</> <fg=cyan>stackd create</> <fg=gray>to get started.</>');
            $output->writeln('');

            return;
        }

        $visible = $runningOnly
            ? array_values(array_filter($services, fn (array $service) => $service['running']))
            : $services;

        $stoppedCount = count(array_filter($services, fn (array $service) => ! $service['running']));

        if ($visible === []) {
            $output->writeln(sprintf(
                '  <fg=yellow>No services running.</>%s',
                $stoppedCount > 0 ? " <fg=gray>{$stoppedCount} stopped</>" : '',
            ));
            $output->writeln('');

            return;
        }

        $serviceWidth = max(array_map(fn (array $s) => mb_strlen($s['service']), $visible));
        $nameWidth = max(array_map(fn (array $s) => mb_strlen($s['name']), $visible));

        foreach ($visible as $service) {
            $dot = $service['running'] ? '<fg=green>●</>' : '<fg=red>○</>';
            $servicePad = str_repeat(' ', max(0, $serviceWidth - mb_strlen($service['service'])));
            $namePad = str_repeat(' ', max(0, $nameWidth - mb_strlen($service['name'])));

            $line = sprintf(
                '  %s <fg=white;options=bold>%s</>%s  <fg=gray>%s</>%s  <fg=cyan>%s</>',
                $dot,
                $service['service'],
                $servicePad,
                $service['name'],
                $namePad,
                $service['address'],
            );

            $credentialsSummary = CredentialFormatter::summary($service['credentials'] ?? []);

            if ($credentialsSummary !== null) {
                $line .= '  <fg=gray>'.$credentialsSummary.'</>';
            }

            if (! $runningOnly) {
                $line .= $service['running']
                    ? '  <fg=green>running</>'
                    : '  <fg=red>stopped</>';
            }

            $output->writeln($line);
        }

        if ($runningOnly && $stoppedCount > 0) {
            $output->writeln(sprintf(
                '  <fg=gray>%d stopped ·</> <fg=cyan>stackd status</>',
                $stoppedCount,
            ));
        }

        $output->writeln('');
    }
}
