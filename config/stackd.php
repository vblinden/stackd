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
    ],

    'default_ports' => [
        'mysql' => 3306,
        'mariadb' => 3307,
        'postgresql' => 5432,
        'valkey' => 6379,
        'mailpit' => 1025,
        'meilisearch' => 7700,
        'minio' => 9000,
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
        'mariadb' => [
            'version' => '11.4.12',
            'url' => 'https://archive.mariadb.org/mariadb-11.4.12/source/mariadb-11.4.12.tar.gz',
            'source_dir' => 'mariadb-11.4.12',
        ],
        'postgresql' => [
            'version' => '18.4.0',
            'arm64' => 'https://github.com/theseus-rs/postgresql-binaries/releases/download/18.4.0/postgresql-18.4.0-aarch64-apple-darwin.tar.gz',
            'x86_64' => 'https://github.com/theseus-rs/postgresql-binaries/releases/download/18.4.0/postgresql-18.4.0-x86_64-apple-darwin.tar.gz',
        ],
        'valkey' => [
            'version' => '9.1.1',
            'url' => 'https://github.com/valkey-io/valkey/archive/refs/tags/9.1.1.tar.gz',
            'source_dir' => 'valkey-9.1.1',
        ],
        'mailpit' => [
            'url' => 'https://github.com/axllent/mailpit/releases/latest/download/mailpit-darwin-{arch}.tar.gz',
        ],
        'meilisearch' => [
            'version' => 'v1.51.0',
            'arm64' => 'https://github.com/meilisearch/meilisearch/releases/download/v1.51.0/meilisearch-macos-apple-silicon',
            'x86_64' => 'https://github.com/meilisearch/meilisearch/releases/download/v1.51.0/meilisearch-macos-amd64',
        ],
        'minio' => [
            'arm64' => 'https://dl.min.io/server/minio/release/darwin-arm64/minio',
            'x86_64' => 'https://dl.min.io/server/minio/release/darwin-amd64/minio',
        ],
    ],

];
