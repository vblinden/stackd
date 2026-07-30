<?php

use App\Support\EnvWriter;
use App\Support\Instance;
use App\Support\InstanceRepository;
use App\Support\ServiceOpener;
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

it('runs the doctor command', function () {
    $exitCode = $this->artisan('doctor')->run();

    expect($exitCode)->toBeIn([0, 1]);
});

it('builds a tableplus mysql connection url', function () {
    $opener = new ServiceOpener;

    $url = $opener->buildConnectionUrl(
        driver: 'mysql',
        host: '127.0.0.1',
        port: 3306,
        user: 'root',
        name: 'stackd mysql:laravel',
    );

    expect($url)->toBe('mysql://root@127.0.0.1:3306?env=local&name=stackd+mysql%3Alaravel');
});

it('builds a tableplus redis connection url for valkey', function () {
    $opener = new ServiceOpener;

    $url = $opener->buildConnectionUrl(
        driver: 'redis',
        host: '127.0.0.1',
        port: 6379,
        name: 'stackd valkey:cache',
    );

    expect($url)->toBe('redis://127.0.0.1:6379?env=local&name=stackd+valkey%3Acache');
});

it('formats credentials for display', function () {
    expect(\App\Support\CredentialFormatter::display([
        'username' => 'root',
        'password' => '',
    ]))->toBe([
        'username' => 'root',
        'password' => '(empty)',
    ])->and(\App\Support\CredentialFormatter::summary([
        'username' => 'laravel',
        'password' => '',
    ]))->toBe('laravel / (empty)');
});

it('applies env for every installed stackd service', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    $paths = new \App\Support\StackdPaths($home);
    $repository = new \App\Support\InstanceRepository($paths);
    $repository->save(new \App\Support\Instance(service: 'mysql', name: 'default', port: 3306));
    $repository->save(new \App\Support\Instance(service: 'mailpit', name: 'default', port: 1025));
    $repository->save(new \App\Support\Instance(service: 'valkey', name: 'default', port: 6379));

    $env = sys_get_temp_dir().'/stackd-env-'.uniqid().'.env';
    file_put_contents($env, <<<'ENV'
DB_CONNECTION=sqlite
MAIL_MAILER=log
CACHE_STORE=database
ENV);

    $services = (new \App\Support\LaravelProjectDetector($repository))->detectNeededServices($env);
    unlink($env);

    expect($services)->toBe(['mysql', 'valkey', 'mailpit']);
});

it('picks pgsql when that connection is set and postgresql is installed', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    $paths = new \App\Support\StackdPaths($home);
    $repository = new \App\Support\InstanceRepository($paths);
    $repository->save(new \App\Support\Instance(service: 'mysql', name: 'default', port: 3306));
    $repository->save(new \App\Support\Instance(service: 'postgresql', name: 'default', port: 5432));

    $env = sys_get_temp_dir().'/stackd-env-'.uniqid().'.env';
    file_put_contents($env, "DB_CONNECTION=pgsql\n");

    $services = (new \App\Support\LaravelProjectDetector($repository))->detectNeededServices($env);
    unlink($env);

    expect($services)->toBe(['postgresql']);
});

it('prefers mysql over other databases for sqlite projects', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    $paths = new \App\Support\StackdPaths($home);
    $repository = new \App\Support\InstanceRepository($paths);
    $repository->save(new \App\Support\Instance(service: 'postgresql', name: 'default', port: 5432));
    $repository->save(new \App\Support\Instance(service: 'mysql', name: 'default', port: 3306));

    $env = sys_get_temp_dir().'/stackd-env-'.uniqid().'.env';
    file_put_contents($env, "DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite\n");

    $services = (new \App\Support\LaravelProjectDetector($repository))->detectNeededServices($env);
    unlink($env);

    expect($services)->toBe(['mysql']);
});

it('overwrites sqlite and mail keys when merging env', function () {
    $env = sys_get_temp_dir().'/stackd-env-'.uniqid().'.env';

    file_put_contents($env, <<<'ENV'
APP_NAME=Checkeroni
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
MAIL_MAILER=log
MAIL_HOST=127.0.0.1
ENV);

    (new \App\Support\EnvWriter)->mergeIntoFile($env, [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'laravel',
        'DB_USERNAME' => 'root',
        'DB_PASSWORD' => '',
        'MAIL_MAILER' => 'smtp',
        'MAIL_HOST' => '127.0.0.1',
        'MAIL_PORT' => '1025',
    ]);

    $contents = file_get_contents($env);
    unlink($env);

    expect($contents)
        ->toContain('APP_NAME=Checkeroni')
        ->toContain('DB_CONNECTION=mysql')
        ->toContain('DB_HOST=127.0.0.1')
        ->toContain('MAIL_MAILER=smtp')
        ->toContain('MAIL_PORT=1025')
        ->not->toContain('DB_CONNECTION=sqlite')
        ->not->toContain('MAIL_MAILER=log')
        ->not->toContain('database/database.sqlite');
});
