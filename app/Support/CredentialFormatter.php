<?php

namespace App\Support;

class CredentialFormatter
{
    /**
     * @param  array<string, string>  $credentials
     * @return array<string, string>
     */
    public static function display(array $credentials): array
    {
        $formatted = [];

        foreach ($credentials as $label => $value) {
            $formatted[$label] = $value === '' ? '(empty)' : $value;
        }

        return $formatted;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public static function summary(array $credentials): ?string
    {
        $formatted = self::display($credentials);

        if ($formatted === []) {
            return null;
        }

        if (isset($formatted['username'], $formatted['password'])) {
            return $formatted['username'].' / '.$formatted['password'];
        }

        $parts = [];

        foreach ($formatted as $label => $value) {
            $parts[] = $label.': '.$value;
        }

        return implode(' · ', $parts);
    }
}
