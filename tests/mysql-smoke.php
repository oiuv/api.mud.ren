<?php

// Dedicated disposable-container check. Never reads .env or accepts a database/host override.
require __DIR__.'/../vendor/autoload.php';

use App\Node;
use App\Thread;
use App\Validators\TicketValidator;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\ClientRepository;

$port = filter_var(getenv('FORUM_TEST_MYSQL_PORT'), FILTER_VALIDATE_INT);
if (!$port || $port < 1024 || $port === 3306 || $port > 65535) {
    throw new RuntimeException('Set FORUM_TEST_MYSQL_PORT to the disposable container port (not 3306).');
}
$bootstrap = new class {
    use Tests\CreatesApplication;
};
$app = $bootstrap->createApplication();
set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
});
config(['database.default' => 'mysql', 'database.connections.mysql' => [
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port,
    'database' => 'codex_forum_upgrade_test', 'username' => 'root', 'password' => '',
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
]]);
$version = DB::selectOne('SELECT VERSION() AS version')->version;
if (!str_starts_with($version, '5.7.40') || DB::select('SHOW TABLES') !== []) {
    throw new RuntimeException('This check requires an empty codex_forum_upgrade_test on MySQL 5.7.40.');
}
Mail::fake();
Queue::fake();
$app->instance(TicketValidator::class, Mockery::mock(TicketValidator::class)->shouldReceive('validate')->andReturn(true)->getMock());

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$request = static function (string $method, string $uri, array $data = [], ?string $token = null) use ($app, $check): array {
    Auth::forgetGuards();
    $request = Request::create($uri, $method, $data);
    $request->headers->set('Accept', 'application/json');
    if ($token) {
        $request->headers->set('Authorization', 'Bearer '.$token);
    }
    $response = $app->make(Kernel::class)->handle($request);
    $check($response->isSuccessful(), $method.' '.$uri.' returned '.$response->getStatusCode());

    return json_decode($response->getContent(), true) ?? [];
};

// First install the historical schema, then exercise the real upgrade over existing rows.
$legacyPaths = array_map(static fn ($path) => 'database/migrations/'.basename($path), array_filter(
    glob(database_path('migrations/*.php')), static fn ($path) => !str_starts_with(basename($path), '2026_')
));
$check(Artisan::call('migrate', ['--path' => $legacyPaths, '--force' => true]) === 0, 'Historical migrations failed.');
DB::table('users')->insert([
    'id' => 73, 'name' => '历史用户', 'username' => 'legacyuser', 'email' => 'legacy@example.test',
    'password' => Hash::make('test-password'), 'avatar' => 'https://example.test/avatar.png',
    'activated_at' => '2020-01-01 00:00:00', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
]);
Node::create(['id' => 1, 'title' => '测试板块']);
DB::table('threads')->insert([
    'id' => 73, 'user_id' => 73, 'node_id' => 1, 'title' => '历史武侠帖子',
    'published_at' => '2020-01-01 00:00:00', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
]);
DB::table('contents')->insert([
    'contentable_id' => 73, 'contentable_type' => 'App\\Thread', 'markdown' => '历史正文 100% _ ! \\', 'body' => '<p>历史正文 100% _ ! \\</p>',
]);
DB::table('oauth_clients')->insert([
    'id' => 73, 'name' => 'Existing frontend', 'secret' => 'existing-secret', 'redirect' => 'http://localhost',
    'password_client' => true, 'personal_access_client' => false, 'revoked' => false,
]);
$check(Artisan::call('migrate', ['--force' => true]) === 0, 'Upgrade migrations failed.');
$check(Hash::check('existing-secret', DB::table('oauth_clients')->where('id', 73)->value('secret')), 'Client secret was not preserved.');
$check(Artisan::call('migrate', ['--force' => true]) === 0, 'Repeated migrate failed.');

$token = $request('POST', '/oauth/token', [
    'grant_type' => 'password', 'client_id' => 73, 'client_secret' => 'existing-secret',
    'username' => 'legacyuser', 'password' => 'test-password', 'scope' => '',
])['access_token'];
$check($request('GET', '/me', [], $token)['id'] === 73, 'Historical user ID changed or login failed.');
$check($request('GET', '/threads/73')['content']['markdown'] === '历史正文 100% _ ! \\', 'RAG body contract changed.');
foreach (['武侠', '%', '_', '!', '\\'] as $term) {
    $result = $request('GET', '/threads/search', ['q' => $term]);
    $check($result['meta']['total'] === 1 && $result['data'][0]['id'] === 73, 'Literal MySQL search failed for '.$term.': '.json_encode($result, JSON_UNESCAPED_UNICODE));
}
DB::table('contents')->where('contentable_type', Thread::class)->where('contentable_id', 73)->update([
    'body' => '<p>历史正文</p><a href="https://example.test">文档</a>',
]);
$check($request('GET', '/threads/search', ['q' => 'href'])['meta']['total'] === 0, 'Rendered Markdown attributes were searchable.');
DB::table('contents')->where('contentable_type', Thread::class)->where('contentable_id', 73)->update([
    'markdown' => null,
    'body' => '<p>C++ &amp; LPC；Soc<strong>ket</strong>；&lt;script&gt;</p><a href="https://example.test">文档</a>',
]);
foreach (['C++ & LPC', 'socket', '<script>'] as $term) {
    $result = $request('GET', '/threads/search', ['q' => $term]);
    $check($result['meta']['total'] === 1 && $result['data'][0]['id'] === 73, 'Visible HTML body was not searchable for '.$term);
    $highlight = $result['data'][0]['highlights']['content'][0];
    $check(str_contains($highlight, '<em>') && !str_contains($highlight, '<script>'), 'HTML body highlight was missing or unsafe.');
}
foreach (['href', 'amp'] as $term) {
    $check($request('GET', '/threads/search', ['q' => $term])['meta']['total'] === 0, 'HTML markup was searchable for '.$term);
}
$created = $request('POST', '/threads', [
    'title' => '升级后的新主题标题', 'node_id' => 1, 'type' => 'markdown',
    'content' => ['markdown' => '保存后的新关键词'], 'ticket' => 'test-ticket',
], $token);
$check($request('GET', '/threads/search', ['q' => '新关键词'])['meta']['total'] === 1, 'New content was not searchable.');
$request('PATCH', '/threads/'.$created['id'], [
    'title' => $created['title'], 'content' => ['markdown' => '修改后关键词'], 'ticket' => 'test-ticket',
], $token);
$check($request('GET', '/threads/search', ['q' => '新关键词'])['meta']['total'] === 0, 'Old content remained searchable.');
$check($request('GET', '/threads/search', ['q' => '修改后关键词'])['meta']['total'] === 1, 'Edited content was not searchable.');
$request('GET', '/threads/'.$created['id']);
$check(Thread::findOrFail($created['id'])->cache['views_count'] === 1, 'MySQL JSON view increment failed.');
DB::table('threads')->where('id', $created['id'])->update(['banned_at' => now()]);
$check($request('GET', '/threads/search', ['q' => '修改后关键词'])['meta']['total'] === 0, 'Banned content leaked.');
app(ClientRepository::class)->createPersonalAccessGrantClient('Registration', 'users');
$check(!empty(App\User::findOrFail(73)->createToken('Check')->accessToken), 'Personal token issuance failed.');
echo "MySQL {$version}: legacy migrations, upgrade, OAuth, RAG, literal search, edits, visibility and JSON counters PASS\n";
