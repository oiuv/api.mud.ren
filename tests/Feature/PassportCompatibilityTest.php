<?php

namespace Tests\Feature;

use App\Mail\Activation;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class PassportCompatibilityTest extends TestCase
{
    private function login($user, $client, string $secret, ?string $identifier = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/oauth/token', [
            'grant_type' => 'password', 'client_id' => $client->id, 'client_secret' => $secret,
            'username' => $identifier ?? $user->username, 'password' => 'test-password', 'scope' => '',
        ]);
    }

    private function bearerGet(string $token, string $url = '/me')
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($url, ['Authorization' => 'Bearer '.$token]);
    }

    public function testPasswordGrantRefreshAndRevocationUsingFrontendRequests()
    {
        $user = UserFactory::new()->activated()->create(['password' => Hash::make('test-password')]);
        $client = app(ClientRepository::class)->createPasswordGrantClient('Frontend', 'users', true);
        $response = $this->login($user, $client, $client->plainSecret)->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in', 'token_type']);
        $token = $response->json('access_token');
        $this->bearerGet($token)->assertOk()->assertJsonPath('id', $user->id);

        $this->app['auth']->forgetGuards();
        $refreshed = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'refresh_token' => $response->json('refresh_token'), 'scope' => '',
        ])->assertOk();
        $newToken = $refreshed->json('access_token');
        $this->bearerGet($newToken)->assertOk();
        $tokenId = DB::table('oauth_access_tokens')->where('revoked', false)->value('id');
        $this->app['auth']->forgetGuards();
        $this->postJson('/oauth/tokens/'.$tokenId, [], ['Authorization' => 'Bearer '.$newToken])->assertNoContent();
        $this->bearerGet($newToken)->assertUnauthorized();
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $tokenId, 'revoked' => true]);
    }

    public function testEmailLoginAndWrongPasswordOrClientAreRejected()
    {
        $user = UserFactory::new()->create(['password' => Hash::make('test-password')]);
        $client = app(ClientRepository::class)->createPasswordGrantClient('Frontend', 'users', true);
        $this->login($user, $client, $client->plainSecret, $user->email)->assertOk();
        $this->login($user, $client, 'wrong-secret')->assertUnauthorized();
        $user->update(['password' => Hash::make('different-password')]);
        $this->login($user, $client, $client->plainSecret)->assertStatus(400);
    }

    public function testUsersCannotRevokeAnotherUsersToken()
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Registration', 'users');
        $owner = UserFactory::new()->create();
        $other = UserFactory::new()->create();
        $ownerToken = $owner->createToken('Owner');
        $otherToken = $other->createToken('Other');
        $this->postJson('/oauth/tokens/'.$otherToken->accessTokenId, [], [
            'Authorization' => 'Bearer '.$ownerToken->accessToken,
        ])->assertNotFound();
        $this->assertDatabaseHas('oauth_access_tokens', ['id' => $otherToken->accessTokenId, 'revoked' => false]);
    }

    public function testRegistrationIssuesUsablePersonalTokenAndQueuesActivationMail()
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Registration', 'users');
        $response = $this->postJson('/auth/register', [
            'username' => 'newmember', 'email' => 'newmember@example.test',
            'password' => 'test-password', 'ticket' => 'test-ticket',
        ])->assertOk()->assertJsonStructure(['token']);
        Mail::assertQueued(Activation::class);
        $this->bearerGet($response->json('token'))->assertOk()->assertJsonPath('username', 'newmember');
    }

    public function testLegacyClientMigrationPreservesIdsSecretsAndLoginWithMatchingUserId()
    {
        Schema::drop('oauth_clients');
        require_once database_path('migrations/2016_06_01_000004_create_oauth_clients_table.php');
        (new \CreateOauthClientsTable())->up();
        $user = UserFactory::new()->create(['password' => Hash::make('test-password')]);
        DB::table('oauth_clients')->insert([
            'id' => $user->id, 'name' => 'Existing frontend', 'secret' => 'existing-client-secret',
            'redirect' => 'http://localhost', 'personal_access_client' => false,
            'password_client' => true, 'revoked' => false,
            'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);
        DB::table('oauth_clients')->insert([
            'id' => $user->id + 1, 'name' => 'Existing registration', 'secret' => 'existing-personal-secret',
            'redirect' => 'http://localhost', 'personal_access_client' => true,
            'password_client' => false, 'revoked' => false,
            'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);
        $migration = require database_path('migrations/2026_09_18_000001_upgrade_oauth_clients.php');
        $migration->up();
        $stored = DB::table('oauth_clients')->first();
        $this->assertSame($user->id, $stored->id);
        $this->assertTrue(Hash::check('existing-client-secret', $stored->secret));
        $this->assertSame(['password', 'refresh_token'], json_decode($stored->grant_types, true));
        $token = $this->login($user, $stored, 'existing-client-secret')->assertOk()->json('access_token');
        $this->bearerGet($token)->assertOk()->assertJsonPath('id', $user->id);
        $personalToken = $user->createToken('Existing registration');
        $this->assertSame($user->id + 1, (int) DB::table('oauth_access_tokens')
            ->where('id', $personalToken->accessTokenId)->value('client_id'));
        $this->bearerGet($personalToken->accessToken)->assertOk()->assertJsonPath('id', $user->id);
    }

    public function testRemovedThirdPartyLoginEndpointsReturnNotFound()
    {
        $this->getJson('/oauth/redirect-url/github')->assertNotFound();
        $this->getJson('/oauth/callback/github')->assertNotFound();
    }
}
