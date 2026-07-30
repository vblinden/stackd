<?php

namespace App\Support;

class EnvWriter
{
    public function mergeIntoFile(string $envPath, array $variables): void
    {
        $existing = file_exists($envPath) ? file_get_contents($envPath) : '';
        $lines = $existing === '' ? [] : preg_split('/\R/', rtrim($existing));

        foreach ($variables as $key => $value) {
            $line = "{$key}={$this->escapeValue($value)}";
            $replaced = false;

            foreach ($lines as $index => $current) {
                if (preg_match('/^'.preg_quote($key, '/').'=/', $current)) {
                    $lines[$index] = $line;
                    $replaced = true;
                    break;
                }
            }

            if (! $replaced) {
                $lines[] = $line;
            }
        }

        file_put_contents($envPath, implode(PHP_EOL, $lines).PHP_EOL);
    }

    public function format(array $variables): string
    {
        $lines = [];

        foreach ($variables as $key => $value) {
            $lines[] = "{$key}={$this->escapeValue($value)}";
        }

        return implode(PHP_EOL, $lines);
    }

    private function escapeValue(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|"/', $value)) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }
}
