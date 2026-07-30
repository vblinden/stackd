<?php

namespace App\Support;

class ProjectDatabase
{
    /**
     * Derive a safe database name from a project directory (folder basename).
     */
    public function nameFromPath(?string $path = null): string
    {
        $basename = basename($path ?? (string) getcwd());
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $basename) ?? '';
        $name = trim($name, '_');

        if ($name === '') {
            return 'laravel';
        }

        if (preg_match('/^[0-9]/', $name) === 1) {
            $name = 'db_'.$name;
        }

        return substr($name, 0, 63);
    }
}
