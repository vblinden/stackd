<?php

namespace App\Support;

class DoctorCheck
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        public readonly string $group,
        public readonly string $label,
        public readonly string $status,
        public readonly string $message,
    ) {}

    public function passed(): bool
    {
        return $this->status === self::PASS;
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }
}
