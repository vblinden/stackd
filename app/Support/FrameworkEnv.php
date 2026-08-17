<?php

namespace App\Support;

class FrameworkEnv
{
    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public function forFramework(?string $framework, array $variables): array
    {
        return match ($framework) {
            ProjectDetector::FRAMEWORK_NEXTJS => $this->forNextJs($variables),
            default => $variables,
        };
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function forNextJs(array $variables): array
    {
        $mapped = [];

        if (isset($variables['DB_HOST'], $variables['DB_PORT'], $variables['DB_DATABASE'])) {
            $mapped['DATABASE_URL'] = $this->databaseUrl($variables);
            $mapped['DB_HOST'] = $variables['DB_HOST'];
            $mapped['DB_PORT'] = $variables['DB_PORT'];
            $mapped['DB_DATABASE'] = $variables['DB_DATABASE'];
            $mapped['DB_USERNAME'] = $variables['DB_USERNAME'] ?? '';
            $mapped['DB_PASSWORD'] = $variables['DB_PASSWORD'] ?? '';
        }

        if (isset($variables['REDIS_HOST'], $variables['REDIS_PORT'])) {
            $mapped['REDIS_URL'] = sprintf('redis://%s:%s', $variables['REDIS_HOST'], $variables['REDIS_PORT']);
            $mapped['REDIS_HOST'] = $variables['REDIS_HOST'];
            $mapped['REDIS_PORT'] = $variables['REDIS_PORT'];
        }

        if (isset($variables['MAIL_HOST'], $variables['MAIL_PORT'])) {
            $mapped['SMTP_HOST'] = $variables['MAIL_HOST'];
            $mapped['SMTP_PORT'] = $variables['MAIL_PORT'];
            $mapped['EMAIL_SERVER'] = sprintf('smtp://%s:%s', $variables['MAIL_HOST'], $variables['MAIL_PORT']);
            $mapped['EMAIL_FROM'] = $variables['MAIL_FROM_ADDRESS'] ?? 'hello@example.com';
        }

        if (isset($variables['STACKD_MAILPIT_WEB'])) {
            $mapped['STACKD_MAILPIT_WEB'] = $variables['STACKD_MAILPIT_WEB'];
        }

        if (isset($variables['MEILISEARCH_HOST'])) {
            $key = $variables['MEILISEARCH_KEY'] ?? '';
            $mapped['MEILISEARCH_HOST'] = $variables['MEILISEARCH_HOST'];
            $mapped['MEILISEARCH_API_KEY'] = $key === 'null' ? '' : $key;
            $mapped['NEXT_PUBLIC_MEILISEARCH_HOST'] = $variables['MEILISEARCH_HOST'];
        }

        foreach ([
            'AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY',
            'AWS_DEFAULT_REGION',
            'AWS_BUCKET',
            'AWS_ENDPOINT',
            'AWS_USE_PATH_STYLE_ENDPOINT',
            'STACKD_MINIO_CONSOLE',
        ] as $key) {
            if (isset($variables[$key])) {
                $mapped[$key] = $variables[$key];
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function databaseUrl(array $variables): string
    {
        $scheme = match ($variables['DB_CONNECTION'] ?? 'mysql') {
            'pgsql', 'postgresql' => 'postgresql',
            default => 'mysql',
        };

        $user = rawurlencode((string) ($variables['DB_USERNAME'] ?? ''));
        $password = (string) ($variables['DB_PASSWORD'] ?? '');
        $auth = $password === '' ? $user : $user.':'.rawurlencode($password);
        $host = $variables['DB_HOST'];
        $port = $variables['DB_PORT'];
        $database = rawurlencode((string) $variables['DB_DATABASE']);

        $url = "{$scheme}://{$auth}@{$host}:{$port}/{$database}";

        if ($scheme === 'postgresql') {
            $url .= '?schema=public';
        }

        return $url;
    }
}
