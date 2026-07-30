<?php

namespace App\Services\Contracts;

use App\Support\Instance;

interface ServiceInterface
{
    public static function type(): string;

    public static function displayName(): string;

    public function defaultPort(): int;

    public function defaultName(): string;

    public function availableVersions(): array;

    public function create(Instance $instance): void;

    public function start(Instance $instance): void;

    public function stop(Instance $instance): void;

    public function isRunning(Instance $instance): bool;

    /**
     * @return array<string, string>
     */
    public function envVariables(Instance $instance): array;

    public function openUrl(Instance $instance): ?string;

    public function openInDatabaseClient(Instance $instance): void;

    /**
     * @return array<int, string>
     */
    public function logFiles(Instance $instance): array;

    public function statusDetails(Instance $instance): array;
}
