<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    private static ?array $passportKeys = null;

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
            'MAIL_MAILER' => 'array',
            'SENTRY_LARAVEL_DSN' => '',
            'SENTRY_DSN' => '',
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
        $app['config']->set('logging.default', 'stderr');
        $app['config']->set('logging.channels.stderr.level', 'warning');
        $app['config']->set('purifier.settings.default.Cache.DefinitionImpl', null);
        if (self::$passportKeys === null) {
            $private = \phpseclib4\Crypt\RSA::createKey(2048);
            self::$passportKeys = [(string) $private, (string) $private->getPublicKey()];
        }
        $app['config']->set('passport.private_key', self::$passportKeys[0]);
        $app['config']->set('passport.public_key', self::$passportKeys[1]);

        return $app;
    }
}
