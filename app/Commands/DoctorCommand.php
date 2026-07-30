<?php

namespace App\Commands;

use App\Support\Doctor;
use App\Support\DoctorCheck;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\spin;
use function Termwind\render;

class DoctorCommand extends Command
{
    protected $signature = 'doctor';

    protected $description = 'Check whether stackd and its dependencies are healthy';

    public function handle(Doctor $doctor): int
    {
        /** @var array<int, DoctorCheck> $checks */
        $checks = spin(fn () => $doctor->run(), 'Running diagnostics...');

        $groups = [];

        foreach ($checks as $check) {
            $groups[$check->group][] = $check;
        }

        render(<<<'HTML'
            <div class="mt-1 ml-1">
                <span class="font-bold text-white">stackd doctor</span>
            </div>
        HTML);

        foreach ($groups as $group => $groupChecks) {
            render(<<<HTML
                <div class="mt-1 ml-1">
                    <span class="font-bold text-gray-400">{$group}</span>
                </div>
            HTML);

            foreach ($groupChecks as $check) {
                [$icon, $color] = match ($check->status) {
                    DoctorCheck::PASS => ['✓', 'text-green-500'],
                    DoctorCheck::WARN => ['!', 'text-yellow-500'],
                    default => ['✗', 'text-red-500'],
                };

                $label = htmlspecialchars($check->label, ENT_QUOTES, 'UTF-8');
                $message = htmlspecialchars($check->message, ENT_QUOTES, 'UTF-8');

                render(<<<HTML
                    <div class="ml-1">
                        <span class="{$color}">{$icon}</span>
                        <span class="ml-1 font-bold text-white">{$label}</span>
                        <span class="ml-1 text-gray-500">{$message}</span>
                    </div>
                HTML);
            }
        }

        $failed = count(array_filter($checks, fn (DoctorCheck $check) => $check->failed()));
        $warned = count(array_filter($checks, fn (DoctorCheck $check) => $check->status === DoctorCheck::WARN));
        $passed = count($checks) - $failed - $warned;

        render(<<<HTML
            <div class="mt-1 ml-1 mb-1">
                <span class="text-green-500">{$passed} passed</span>
                <span class="ml-1 text-yellow-500">{$warned} warnings</span>
                <span class="ml-1 text-red-500">{$failed} failed</span>
            </div>
        HTML);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
