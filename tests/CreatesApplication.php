<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    public function createApplication()
    {
        // Never load the local .env or a production config/route cache during tests.
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'APP_CONFIG_CACHE' => 'tests/unused-config-'.getmypid().'.php',
            'APP_ROUTES_CACHE' => 'tests/unused-routes-'.getmypid().'.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_DRIVER' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_DRIVER' => 'sync',
            'MAIL_DRIVER' => 'array',
            'SCOUT_QUEUE' => 'true',
            'BCRYPT_ROUNDS' => '4',
        ];
        foreach ($environment as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app = require __DIR__.'/../bootstrap/app.php';
        $app->loadEnvironmentFrom('tests/.env.unused');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections', [
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        $app['config']->set('scout.queue', true);
        $app['config']->set('scout.driver', 'null');
        $app['config']->set('logging.default', 'stderr');
        $app['config']->set('purifier.settings.default.Cache.DefinitionImpl', null);

        return $app;
    }
}
