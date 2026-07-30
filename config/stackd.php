<?php

return [

    'home' => getenv('STACKD_HOME') ?: rtrim(getenv('HOME') ?: '', '/').'/.stackd',

    'bind_address' => '127.0.0.1',

    'services' => [
        'mysql',
        'mariadb',
        'postgresql',
        'valkey',
        'mailpit',
        'meilisearch',
        'minio',
        'reverb',
    ],

    'default_ports' => [
        'mysql' => 3306,
        'mariadb' => 3307,
        'postgresql' => 5432,
        'valkey' => 6379,
        'mailpit' => 1025,
        'meilisearch' => 7700,
        'minio' => 9000,
        'reverb' => 8080,
    ],

    'downloads' => [
        'mysql' => [
            '8.4' => [
                'release' => '8.4.11',
                'arm64' => 'https://cdn.mysql.com/Downloads/MySQL-8.4/mysql-8.4.11-macos15-arm64.tar.gz',
                'x86_64' => 'https://cdn.mysql.com/Downloads/MySQL-8.4/mysql-8.4.11-macos15-x86_64.tar.gz',
            ],
            '8.0' => [
                'release' => '8.0.44',
                'arm64' => 'https://cdn.mysql.com/Downloads/MySQL-8.0/mysql-8.0.44-macos15-arm64.tar.gz',
                'x86_64' => 'https://cdn.mysql.com/Downloads/MySQL-8.0/mysql-8.0.44-macos15-x86_64.tar.gz',
            ],
            'latest' => '8.4',
        ],
        'valkey' => [
            'version' => '9.1.1',
            'url' => 'https://github.com/valkey-io/valkey/archive/refs/tags/9.1.1.tar.gz',
            'source_dir' => 'valkey-9.1.1',
        ],
        'mailpit' => [
            'url' => 'https://github.com/axllent/mailpit/releases/latest/download/mailpit-darwin-{arch}.tar.gz',
        ],
    ],

];
