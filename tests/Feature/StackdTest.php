<?php

use App\Services\MailpitService;
use App\Services\MySqlService;
use App\Services\PostgreSqlService;
use App\Support\CredentialFormatter;
use App\Support\DockerEngine;
use App\Support\DockerSpec;
use App\Support\EnvWriter;
use App\Support\HomebrewConflict;
use App\Support\Instance;
use App\Support\InstanceManager;
use App\Support\InstanceRepository;
use App\Support\LaravelProjectDetector;
use App\Support\LaunchAgentManager;
use App\Support\ProcessManager;
use App\Support\ProjectDatabase;
use App\Support\ServiceOpener;
use App\Support\StackdConfig;
use App\Support\StackdPaths;
use Symfony\Component\Process\Process;

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

it('starts and stops all instances when no service is given', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    app()->forgetInstance(StackdPaths::class);
    app()->instance(StackdPaths::class, new StackdPaths($home));
    app()->forgetInstance(InstanceRepository::class);
    app()->forgetInstance(InstanceManager::class);

    $this->artisan('start')->assertSuccessful();
    $this->artisan('stop')->assertSuccessful();

    $paths = new StackdPaths($home);
    $repository = new InstanceRepository($paths);
    $repository->save(new Instance(service: 'mailpit', name: 'default', port: 1025));
    $repository->save(new Instance(service: 'valkey', name: 'cache', port: 6379));

    app()->forgetInstance(InstanceRepository::class);
    app()->forgetInstance(InstanceManager::class);

    $manager = app(InstanceManager::class);

    // stopAll should walk every registered instance even when none are running.
    expect($manager->stopAll())->toHaveCount(2);
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
    expect(CredentialFormatter::display([
        'username' => 'root',
        'password' => '',
    ]))->toBe([
        'username' => 'root',
        'password' => '(empty)',
    ])->and(CredentialFormatter::summary([
        'username' => 'laravel',
        'password' => '',
    ]))->toBe('laravel / (empty)');
});

it('applies env for every installed stackd service', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    $paths = new StackdPaths($home);
    $repository = new InstanceRepository($paths);
    $repository->save(new Instance(service: 'mysql', name: 'default', port: 3306));
    $repository->save(new Instance(service: 'mailpit', name: 'default', port: 1025));
    $repository->save(new Instance(service: 'valkey', name: 'default', port: 6379));

    $env = sys_get_temp_dir().'/stackd-env-'.uniqid().'.env';
    file_put_contents($env, <<<'ENV'
DB_CONNECTION=sqlite
MAIL_MAILER=log
CACHE_STORE=database
ENV);

    $services = (new LaravelProjectDetector($repository))->detectNeededServices($env);
    unlink($env);

    expect($services)->toBe(['mysql', 'valkey', 'mailpit']);
});

it('picks pgsql when that connection is set and postgresql is installed', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    $paths = new StackdPaths($home);
    $repository = new InstanceRepository($paths);
    $repository->save(new Instance(service: 'mysql', name: 'default', port: 3306));
    $repository->save(new Instance(service: 'postgresql', name: 'default', port: 5432));

    $env = sys_get_temp_dir().'/stackd-env-'.uniqid().'.env';
    file_put_contents($env, "DB_CONNECTION=pgsql\n");

    $services = (new LaravelProjectDetector($repository))->detectNeededServices($env);
    unlink($env);

    expect($services)->toBe(['postgresql']);
});

it('prefers mysql over other databases for sqlite projects', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    $paths = new StackdPaths($home);
    $repository = new InstanceRepository($paths);
    $repository->save(new Instance(service: 'postgresql', name: 'default', port: 5432));
    $repository->save(new Instance(service: 'mysql', name: 'default', port: 3306));

    $env = sys_get_temp_dir().'/stackd-env-'.uniqid().'.env';
    file_put_contents($env, "DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite\n");

    $services = (new LaravelProjectDetector($repository))->detectNeededServices($env);
    unlink($env);

    expect($services)->toBe(['mysql']);
});

it('detects conflicting Homebrew formula names', function () {
    $homebrew = new HomebrewConflict;

    expect($homebrew->filterConflicts([
        'mysql',
        'mysql@8.4',
        'mysql-client',
        'openssl@3',
        'redis',
        'postgresql@16',
        'cmake',
    ], 'mysql'))->toBe(['mysql', 'mysql@8.4'])
        ->and($homebrew->filterConflicts([
            'mysql',
            'redis',
            'valkey',
            'postgresql@16',
        ], 'valkey'))->toBe(['redis', 'valkey'])
        ->and($homebrew->filterConflicts([
            'mysql',
            'redis',
            'postgresql@16',
            'openssl@3',
        ]))->toBe(['mysql', 'postgresql@16', 'redis']);
});

it('derives a safe database name from the project folder', function () {
    $project = new ProjectDatabase;

    expect($project->nameFromPath('/Users/vblinden/Code/vblinden/checkeroni'))->toBe('checkeroni')
        ->and($project->nameFromPath('/tmp/My App!'))->toBe('My_App')
        ->and($project->nameFromPath('/tmp/123site'))->toBe('db_123site');
});

it('rejects unsafe instance names before they can become paths', function () {
    expect(fn () => new Instance(service: 'mysql', name: '../outside', port: 3306))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Instance(service: 'mysql', name: 'cache;touch', port: 3306))
        ->toThrow(InvalidArgumentException::class);
});

it('reserves ports globally, including service companion ports', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    $paths = new StackdPaths($home);
    $repository = new InstanceRepository($paths);
    $repository->save(new Instance(
        service: 'mailpit',
        name: 'default',
        port: 1025,
        options: ['web_port' => 8025],
    ));

    expect($repository->isPortAvailable(1025))->toBeFalse()
        ->and($repository->isPortAvailable(8025))->toBeFalse()
        ->and($repository->isPortAvailable(80))->toBeFalse();
});

it('cleans up stale PID files when process does not exist or command mismatches', function () {
    $pidFile = sys_get_temp_dir().'/stackd-pid-'.uniqid();
    file_put_contents($pidFile, '9999999');

    $processes = new ProcessManager;

    expect($processes->isRunning($pidFile))->toBeFalse()
        ->and(file_exists($pidFile))->toBeFalse();

    file_put_contents($pidFile, json_encode([
        'pid' => getmypid(),
        'command' => 'non_existent_binary_name_12345',
    ]));

    expect($processes->isRunning($pidFile))->toBeFalse()
        ->and(file_exists($pidFile))->toBeFalse();
});

it('recognizes plain integer PID files for running processes without killing on stop', function () {
    $pidFile = sys_get_temp_dir().'/stackd-pid-'.uniqid();
    $selfPid = getmypid();
    file_put_contents($pidFile, (string) $selfPid);

    $processes = new ProcessManager;

    expect($processes->isRunning($pidFile))->toBeTrue()
        ->and(file_exists($pidFile))->toBeTrue();

    $processes->stop($pidFile);

    expect(file_exists($pidFile))->toBeFalse()
        ->and(posix_kill($selfPid, 0))->toBeTrue();
});

it('does not kill processes for JSON PID files missing a command', function () {
    $pidFile = sys_get_temp_dir().'/stackd-pid-'.uniqid();
    $selfPid = getmypid();
    file_put_contents($pidFile, json_encode(['pid' => $selfPid]));

    $processes = new ProcessManager;

    expect($processes->isRunning($pidFile))->toBeTrue();

    $processes->stop($pidFile);

    expect(file_exists($pidFile))->toBeFalse()
        ->and(posix_kill($selfPid, 0))->toBeTrue();
});

it('rejects non-decimal legacy PID file contents', function () {
    $pidFile = sys_get_temp_dir().'/stackd-pid-'.uniqid();
    file_put_contents($pidFile, '1e2');

    $processes = new ProcessManager;

    expect($processes->isRunning($pidFile))->toBeFalse()
        ->and(file_exists($pidFile))->toBeFalse();
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

    (new EnvWriter)->mergeIntoFile($env, [
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

it('writes launchd autostart scripts with absolute php and stackd paths', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    $launchAgents = $home.'/LaunchAgents';
    mkdir($home, 0755, true);

    $paths = new StackdPaths($home, $launchAgents);
    $processes = Mockery::mock(ProcessManager::class);
    $processes->shouldReceive('run')->andReturnUsing(function () {
        $process = new Process(['true']);
        $process->run();

        return $process;
    });

    $autostart = new LaunchAgentManager(
        $paths,
        $processes,
        new InstanceRepository($paths),
        new DockerEngine($processes, 'docker'),
    );
    $autostart->add('mysql', 'default');

    $script = file_get_contents($home.'/autostart.sh');
    $plist = file_get_contents($launchAgents.'/com.stackd.autostart.plist');

    expect($script)
        ->toContain(PHP_BINARY)
        ->toContain('autostart')
        ->toContain('run')
        ->not->toContain("'stackd' 'start'")
        ->and($plist)
        ->toContain('<key>PATH</key>')
        ->toContain('<key>HOME</key>')
        ->toContain('<key>RunAtLoad</key>');
});

it('starts listed autostart instances via autostart run', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    $paths = new StackdPaths($home);
    file_put_contents($paths->autostart(), json_encode([
        'enabled' => true,
        'instances' => [],
    ]));

    app()->forgetInstance(StackdPaths::class);
    app()->instance(StackdPaths::class, $paths);
    app()->forgetInstance(LaunchAgentManager::class);
    app()->forgetInstance(InstanceManager::class);

    $this->artisan('autostart', ['action' => 'run'])->assertSuccessful();
});

it('defaults the runtime setting to native and can switch to docker', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    $paths = new StackdPaths($home);
    $config = new StackdConfig($paths);

    expect($config->runtime())->toBe('native');

    $config->setRuntime('docker');

    expect($config->runtime())->toBe('docker')
        ->and(json_decode((string) file_get_contents($paths->config()), true)['runtime'])->toBe('docker');
});

it('treats registry entries without a runtime as native', function () {
    $instance = Instance::fromArray([
        'service' => 'mysql',
        'name' => 'default',
        'port' => 3306,
    ]);

    expect($instance->runtime)->toBe('native')
        ->and($instance->isDocker())->toBeFalse()
        ->and($instance->toArray()['runtime'])->toBe('native');
});

it('builds a loopback docker run command from a spec', function () {
    $engine = new DockerEngine(new ProcessManager, 'docker');
    $instance = new Instance(service: 'mysql', name: 'default', port: 3306, runtime: 'docker');
    $spec = new DockerSpec(
        image: 'mysql:8.4',
        ports: [3306 => 3306],
        env: ['MYSQL_ALLOW_EMPTY_PASSWORD' => 'yes'],
        volumes: ['/tmp/stackd-mysql' => '/var/lib/mysql'],
    );

    expect($engine->containerName($instance))->toBe('stackd-mysql-default')
        ->and($engine->buildRunCommand($instance, $spec))->toBe([
            'docker',
            'run',
            '-d',
            '--name', 'stackd-mysql-default',
            '--label', 'com.stackd.managed=1',
            '--label', 'com.stackd.instance=mysql:default',
            '--restart', 'no',
            '-p', '127.0.0.1:3306:3306',
            '-e', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes',
            '-v', '/tmp/stackd-mysql:/var/lib/mysql',
            'mysql:8.4',
        ]);
});

it('describes official docker images for mysql postgres and mailpit', function () {
    $mysql = app(MySqlService::class)->dockerSpec(new Instance(
        service: 'mysql',
        name: 'default',
        port: 3306,
        version: '8.4',
        runtime: 'docker',
    ));
    $postgres = app(PostgreSqlService::class)->dockerSpec(new Instance(
        service: 'postgresql',
        name: 'default',
        port: 5432,
        runtime: 'docker',
    ));
    $mailpit = app(MailpitService::class)->dockerSpec(new Instance(
        service: 'mailpit',
        name: 'default',
        port: 1025,
        options: ['web_port' => 8025],
        runtime: 'docker',
    ));

    expect($mysql->image)->toBe('mysql:8.4')
        ->and($mysql->ports)->toBe([3306 => 3306])
        ->and($postgres->image)->toBe('postgres:18')
        ->and($postgres->env['POSTGRES_HOST_AUTH_METHOD'])->toBe('trust')
        ->and($mailpit->image)->toBe('axllent/mailpit')
        ->and($mailpit->ports)->toBe([1025 => 1025, 8025 => 8025])
        ->and($mailpit->command)->toContain('--database');
});

it('shows and sets the runtime command', function () {
    $home = sys_get_temp_dir().'/stackd-test-'.uniqid();
    mkdir($home, 0755, true);
    config(['stackd.home' => $home]);

    app()->forgetInstance(StackdPaths::class);
    app()->instance(StackdPaths::class, new StackdPaths($home));
    app()->forgetInstance(StackdConfig::class);

    $this->artisan('runtime')->assertSuccessful();
    $this->artisan('runtime', ['choice' => 'docker'])->assertSuccessful();

    expect(app(StackdConfig::class)->runtime())->toBe('docker');
});

it('rejects creating with both --docker and --native', function () {
    $this->artisan('create', [
        'service' => 'mailpit',
        '--docker' => true,
        '--native' => true,
    ])->assertFailed();
});
