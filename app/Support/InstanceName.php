<?php

namespace App\Support;

use InvalidArgumentException;

class InstanceName
{
    public static function assertValid(string $name): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,62}\z/', $name) !== 1) {
            throw new InvalidArgumentException(
                'Instance names must be 1-63 characters and contain only letters, numbers, hyphens, and underscores.'
            );
        }
    }
}
