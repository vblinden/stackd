<?php

namespace App\Services\Contracts;

use App\Support\Instance;

interface ManagesNamedDatabases
{
    public function databaseExists(Instance $instance, string $database): bool;

    public function createDatabase(Instance $instance, string $database): void;
}
