<?php

use App\Support\EnvWriter;
use App\Support\Instance;
use App\Support\InstanceRepository;
use App\Support\StackdPaths;

it('creates instance metadata', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);

    config(['stackd.home' => $home]);

    $paths = new StackdPaths($home);
    $repository = new InstanceRepository($paths);

    $instance = new Instance(
        service: 'mailpit',
        name: 'default',
        port: 1025,
    );

    $repository->save($instance);

    expect($repository->find('mailpit', 'default'))->not->toBeNull()
        ->and($repository->find('mailpit', 'default')->port)->toBe(1025);

    $repository->delete('mailpit', 'default');

    expect($repository->find('mailpit', 'default'))->toBeNull();
});

it('formats env variables', function () {
    $writer = new EnvWriter;

    $output = $writer->format([
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'APP_NAME' => 'My App',
    ]);

    expect($output)->toContain('DB_HOST=127.0.0.1')
        ->and($output)->toContain('APP_NAME="My App"');
});

it('shows home screen with help', function () {
    $this->artisan('home')->assertSuccessful();
});

it('lists available services when create has no argument', function () {
    $this->artisan('create')->assertSuccessful();
});

it('resolves dotted mysql version keys from config', function () {
    $downloads = config('stackd.downloads.mysql');

    expect($downloads['8.4']['release'] ?? null)->toBe('8.4.11')
        ->and(config('stackd.downloads.mysql.8.4'))->toBeNull();
});

it('builds a tableplus mysql connection url', function () {
    $opener = new App\Support\ServiceOpener;

    $url = $opener->buildConnectionUrl(
        driver: 'mysql',
        host: '127.0.0.1',
        port: 3306,
        database: 'laravel',
        user: 'root',
        name: 'stackd mysql:laravel',
    );

    expect($url)->toBe('mysql://root@127.0.0.1:3306/laravel?env=local&name=stackd+mysql%3Alaravel');
});
