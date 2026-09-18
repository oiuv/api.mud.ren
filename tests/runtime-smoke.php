<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;

$bootstrap = new class {
    use Tests\CreatesApplication;
};
$app = $bootstrap->createApplication();
$paths = [$app->getCachedConfigPath(), $app->getCachedRoutesPath()];
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$exitCode = 0;
try {
    $kernel = $app->make(Kernel::class);
    $check($kernel->call('route:list', ['--json' => true]) === 0, 'Route listing failed.');
    $routes = json_decode($kernel->output(), true, 512, JSON_THROW_ON_ERROR);
    $uris = array_column($routes, 'uri');
    foreach (['threads/search', 'threads/{thread}', 'oauth/token', 'oauth/tokens/{token}', 'me'] as $uri) {
        $check(in_array($uri, $uris, true), 'Missing route '.$uri);
    }
    foreach (['config:cache', 'route:cache'] as $command) {
        $check($kernel->call($command) === 0, $command.' failed.');
    }

    $cached = require __DIR__.'/../bootstrap/app.php';
    $cached->make(Kernel::class)->bootstrap();
    $check($cached->configurationIsCached() && $cached->routesAreCached(), 'Caches were not loaded.');
    $check($cached['config']['database.default'] === 'sqlite', 'Database isolation was lost.');
    $check($cached['config']['mail.default'] === 'array', 'Mail isolation was lost.');
    $check($cached->make(Kernel::class)->call('route:list', ['--json' => true]) === 0, 'Cached route listing failed.');
    $cachedRoutes = json_decode($cached->make(Kernel::class)->output(), true, 512, JSON_THROW_ON_ERROR);
    $check(count($routes) === count($cachedRoutes), 'Route count changed with caching.');
    echo 'Laravel '.$cached->version().': '.count($routes)." routes, config/route cache generation and cached boot PASS\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    $exitCode = 1;
} finally {
    foreach ($paths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
exit($exitCode);
