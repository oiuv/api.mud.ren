<?php

namespace Tests\Feature;

use App\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class RecentActiveUsersTest extends TestCase
{
    private function recordedUser(int $minutesAgo, array $attributes = []): User
    {
        $user = UserFactory::new()->activated()->create();
        DB::table('users')->where('id', $user->id)->update(array_merge([
            'last_active_at' => now()->subMinutes($minutesAgo),
        ], $attributes));

        return $user->fresh();
    }

    private function tokenFor(User $user): string
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Activity test', 'users');

        return $user->createToken('Activity test')->accessToken;
    }

    private function bearerGet(string $token, string $url)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($url, ['Authorization' => 'Bearer '.$token]);
    }

    public function testRecentUsersArePublicSortedPaginatedAndKeepActivityTimesPrivate(): void
    {
        $this->travelTo(now()->startOfSecond());
        $old = $this->recordedUser(30, ['energy' => 9999]);
        $first = $this->recordedUser(1);
        $tied = $this->recordedUser(1);
        $this->recordedUser(0, ['banned_at' => now()]);
        $this->recordedUser(0, ['deleted_at' => now()]);
        $this->recordedUser(0, ['activated_at' => null]);
        UserFactory::new()->activated()->create();

        $response = $this->getJson('/users?order=recentActive&limit=2')->assertOk()
            ->assertJsonPath('meta.order', 'recentActive')->assertJsonPath('meta.total', 3)
            ->assertJsonCount(2, 'data');
        $this->assertSame([$tied->id, $first->id], array_column($response->json('data'), 'id'));
        foreach ($response->json('data') as $user) {
            foreach (['last_active_at', 'email', 'settings'] as $field) {
                $this->assertArrayNotHasKey($field, $user);
            }
        }
        $this->getJson('/users?order=recentActive&limit=2&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $old->id)->assertJsonCount(1, 'data');
    }

    public function testEmptyHistoryDoesNotFallBackToRegistrationOrExperience(): void
    {
        UserFactory::new()->activated()->create(['energy' => 9999]);
        $this->getJson('/users?order=recentActive&limit=20')->assertOk()
            ->assertJsonPath('meta.order', 'recentActive')->assertJsonCount(0, 'data');
    }

    public function testAuthenticatedVisitsRecordActivityWithoutChangingProfileOrExperience(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = UserFactory::new()->activated()->create(['energy' => 42]);
        $updatedAt = $user->updated_at->toDateTimeString();
        $token = $this->tokenFor($user);
        $this->travel(10)->minutes();

        // /me is also called by the forum after login and when restoring a session.
        $this->bearerGet($token, '/me')->assertOk();
        $user->refresh();
        $this->assertSame(now()->toDateTimeString(), $user->last_active_at->toDateTimeString());
        $this->assertSame($updatedAt, $user->updated_at->toDateTimeString());
        $this->assertSame(42, $user->energy);

        $this->travel(5)->minutes();
        // Public endpoints with a valid token must also count as a visit.
        $this->bearerGet($token, '/users?latest=1&limit=1')->assertOk();
        $this->assertSame(now()->toDateTimeString(), $user->fresh()->last_active_at->toDateTimeString());
        $this->assertSame($updatedAt, $user->fresh()->updated_at->toDateTimeString());
    }

    public function testActivityWritesAreLimitedToOnceEveryFiveMinutesEvenWithAStaleUserInstance(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = UserFactory::new()->activated()->create();
        $this->actingAs($user, 'api')->getJson('/me')->assertOk();
        $firstActivity = $user->fresh()->last_active_at->toDateTimeString();

        $this->travel(4)->minutes();
        $this->getJson('/me')->assertOk();
        $this->assertSame($firstActivity, $user->fresh()->last_active_at->toDateTimeString());
        $this->travel(1)->minutes();
        $this->getJson('/me')->assertOk();
        $this->assertSame(now()->toDateTimeString(), $user->fresh()->last_active_at->toDateTimeString());
    }

    public function testGuestsInvalidTokensAndIneligibleUsersDoNotRecordActivity(): void
    {
        $user = UserFactory::new()->activated()->create();
        $this->getJson('/users?order=recentActive')->assertOk();
        $this->bearerGet('not-a-valid-token', '/users?order=recentActive')->assertOk();
        $this->bearerGet('not-a-valid-token', '/me')->assertUnauthorized();
        $this->assertNull($user->fresh()->last_active_at);

        $pending = UserFactory::new()->create();
        $this->actingAs($pending, 'api')->getJson('/me')->assertOk();
        $this->assertNull($pending->fresh()->last_active_at);

        DB::table('users')->where('id', $user->id)->update(['banned_at' => now()]);
        $this->actingAs($user->fresh(), 'api')->getJson('/me')->assertOk();
        $this->assertNull($user->fresh()->last_active_at);
    }

    public function testFailedRequestsAndClientSuppliedTimestampsCannotForgeActivity(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = UserFactory::new()->activated()->create();
        $other = UserFactory::new()->activated()->create();
        $this->actingAs($user, 'api')->patchJson('/users/'.$other->username, ['name' => 'Forbidden'])->assertForbidden();
        $this->assertNull($user->fresh()->last_active_at);
        $this->getJson('/users/missing-user')->assertNotFound();
        $this->assertNull($user->fresh()->last_active_at);

        $this->patchJson('/users/'.$user->username, ['last_active_at' => now()->addYear()->toDateTimeString()])->assertOk();
        $this->assertSame(now()->toDateTimeString(), $user->fresh()->last_active_at->toDateTimeString());
        $this->assertNull($other->fresh()->last_active_at);
    }

    public function testExistingRankingAndRegistrationSortOrdersArePreserved(): void
    {
        $this->travelTo(now()->startOfSecond());
        $experienced = $this->recordedUser(20, ['energy' => 9999, 'created_at' => now()->subYear()]);
        $newest = $this->recordedUser(30, ['energy' => 1]);
        $this->getJson('/users?order=ranking&limit=1')->assertOk()->assertJsonPath('data.0.id', $experienced->id);
        $this->getJson('/users?latest=1&limit=1')->assertOk()->assertJsonPath('data.0.id', $newest->id);
    }
}
