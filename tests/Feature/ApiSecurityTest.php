<?php

namespace Tests\Feature;

use App\Jobs\FetchContentMentions;
use App\Jobs\ThreadAddPopular;
use App\Thread;
use App\User;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Mews\Purifier\Facades\Purifier;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    protected function createThread(array $attributes = [])
    {
        $user = \Database\Factories\UserFactory::new()->activated()->create();
        $response = $this->actingAs($user, 'api')->postJson('/threads', $this->threadPayload($attributes));
        $response->assertStatus(201);

        return Thread::findOrFail($response->json('id'));
    }

    protected function asGuest()
    {
        $guard = $this->app['auth']->guard('api');
        $guard->forgetUser();
        $this->assertTrue($guard->guest());
    }

    public function testProfileUpdatesAuthorizeTheTargetUser()
    {
        $owner = \Database\Factories\UserFactory::new()->create();
        $other = \Database\Factories\UserFactory::new()->create();
        $this->actingAs($owner, 'api')->patchJson('/users/'.$other->username, ['name' => 'Stolen profile'])
            ->assertStatus(403);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->patchJson('/users/'.$owner->username, ['name' => 'My profile'])->assertStatus(200);
        $this->assertSame('My profile', $owner->fresh()->name);
    }

    public function testUsersCannotChangeASingleSensitiveFieldOrUnbanThemselves()
    {
        $user = \Database\Factories\UserFactory::new()->create();
        $this->actingAs($user, 'api')->patchJson('/users/'.$user->username, ['banned_at' => now()->toDateTimeString()])
            ->assertStatus(403);
        $this->assertNull($user->fresh()->banned_at);
        DB::table('users')->where('id', $user->id)->update(['banned_at' => now()]);
        $this->actingAs($user->fresh(), 'api')->patchJson('/users/'.$user->username, ['banned_at' => null])
            ->assertStatus(403);
        $this->assertNotNull($user->fresh()->banned_at);
    }

    public function testProfileInputCannotElevatePrivilegesOrOverwriteCounters()
    {
        $user = \Database\Factories\UserFactory::new()->create();
        $this->actingAs($user, 'api')->patchJson('/users/'.$user->username, [
            'name' => 'Allowed name', 'is_admin' => true, 'energy' => 9999, 'cache' => ['threads_count' => 9999],
        ])->assertStatus(200);
        $user->refresh();
        $this->assertFalse($user->is_admin);
        $this->assertSame(0, $user->energy);
        $this->assertSame(0, $user->cache['threads_count']);
    }

    public function testAdminCanStillBanAndUnbanAnotherUser()
    {
        $admin = \Database\Factories\UserFactory::new()->admin()->create();
        $user = \Database\Factories\UserFactory::new()->create();
        $this->actingAs($admin, 'api')->patchJson('/users/'.$user->username, ['banned_at' => now()->toDateTimeString()])
            ->assertStatus(200);
        $this->assertNotNull($user->fresh()->banned_at);
        $this->patchJson('/users/'.$user->username, ['banned_at' => null])->assertStatus(200);
        $this->assertNull($user->fresh()->banned_at);
    }

    public function testUsernameLookupTreatsSqlAsLiteralInput()
    {
        \Database\Factories\UserFactory::new()->create(['username' => 'KnownUser']);
        $this->postJson('/user/exists', ['username' => 'knownuser'])->assertJson(['success' => false]);
        $this->postJson('/user/exists', ['username' => '" OR 1=1 -- '])->assertStatus(200)->assertJson(['success' => true]);
        $this->postJson('/user/exists', ['username' => ['invalid']])->assertStatus(422);
    }

    public function testExpiredPasswordResetTokenCannotChangePassword()
    {
        $user = \Database\Factories\UserFactory::new()->create();
        $token = Password::broker()->createToken($user);
        DB::table('password_resets')->where('email', $user->email)->update([
            'created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1),
        ]);
        $this->postJson('/user/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function testResetTokenIsBoundToItsUserAndCanOnlyBeUsedOnce()
    {
        Event::fake([PasswordReset::class]);
        $user = \Database\Factories\UserFactory::new()->create();
        $other = \Database\Factories\UserFactory::new()->create();
        $token = Password::broker()->createToken($user);
        $payload = [
            'email' => $other->email, 'token' => $token,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ];
        $this->postJson('/user/reset-password', $payload)->assertStatus(422);
        $this->assertSame($other->password, $other->fresh()->password);
        $payload['email'] = $user->email;
        $this->postJson('/user/reset-password', $payload)->assertStatus(200)->assertJson(['status' => 200]);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertNotSame($user->remember_token, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('password_resets', ['email' => $user->email]);
        Event::assertDispatched(PasswordReset::class);
        $this->postJson('/user/reset-password', $payload)->assertStatus(422);
    }

    public function testChangingPasswordRequiresLoginAndTheCorrectOldPassword()
    {
        $payload = ['old_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/user/reset-password', $payload)->assertStatus(401);
        $user = \Database\Factories\UserFactory::new()->create(['password' => Hash::make('old-password')]);
        $this->actingAs($user, 'api')->postJson('/user/reset-password', array_merge($payload, ['old_password' => 'wrong']))
            ->assertStatus(422);
        $this->postJson('/user/reset-password', $payload)->assertStatus(200);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function testPublicReadsIgnoreContentAndDraftParameters()
    {
        $thread = $this->createThread();
        $publishedAt = $thread->published_at;
        $updatedAt = $thread->updated_at;
        $this->asGuest();
        $url = '/threads/'.$thread->id.'?'.http_build_query([
            'is_draft' => true, 'type' => 'markdown', 'content' => ['markdown' => 'Injected body'],
        ]);
        Carbon::setTestNow(now()->addMinute());
        try {
            $this->getJson($url)->assertStatus(200)->assertJsonFragment(['markdown' => 'hello every one.']);
        } finally {
            Carbon::setTestNow();
        }
        $thread->refresh();
        $this->assertTrue($publishedAt->eq($thread->published_at));
        $this->assertTrue($updatedAt->eq($thread->updated_at));
        $this->assertSame('hello every one.', $thread->content->markdown);
        $this->assertSame(1, $thread->cache['views_count']);
    }

    public function testDraftsStayPrivateAndReadsNeverPublishThem()
    {
        $thread = $this->createThread(['is_draft' => true]);
        $owner = $thread->user;
        $this->asGuest();
        $this->getJson('/threads/'.$thread->id)->assertStatus(404);
        $other = \Database\Factories\UserFactory::new()->activated()->create();
        $this->actingAs($other, 'api')->getJson('/threads/'.$thread->id)->assertStatus(404);
        $this->actingAs($owner, 'api')->getJson('/threads/'.$thread->id)->assertStatus(200);
        $thread->refresh()->refreshCache();
        $this->assertNull($thread->fresh()->published_at);
        $this->assertSame(0, $thread->fresh()->cache['views_count']);
        Queue::assertNotPushed(ThreadAddPopular::class);
        $this->assertDatabaseMissing('activity_log', ['log_name' => 'published.thread', 'subject_id' => $thread->id]);
        $this->patchJson('/threads/'.$thread->id, $this->threadPayload(['is_draft' => false]))->assertStatus(200);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'published.thread', 'subject_id' => $thread->id]);
        $this->asGuest();
        $this->getJson('/threads/'.$thread->id)->assertStatus(200);
    }

    public function testEditingTheBodyWorksWithoutChangingTitleAndDoesNotTransferOwnership()
    {
        $thread = $this->createThread();
        $other = \Database\Factories\UserFactory::new()->create();
        $this->patchJson('/threads/'.$thread->id, $this->threadPayload([
            'content' => ['markdown' => 'Only the body changed'],
            'user_id' => $other->id, 'cache' => ['views_count' => 9999],
        ]))->assertStatus(200)->assertJsonFragment(['markdown' => 'Only the body changed']);
        $thread->refresh();
        $this->assertNotSame($other->id, $thread->user_id);
        $this->assertSame(0, $thread->cache['views_count']);
    }

    public function testContentUpdatesCannotMoveTheBodyToAnotherThread()
    {
        $thread = $this->createThread();
        $content = $thread->content;
        $this->patchJson('/contents/'.$content->id, [
            'markdown' => 'Edited body', 'contentable_id' => 999, 'contentable_type' => User::class,
        ])->assertStatus(200);
        $this->assertSame($thread->id, $content->fresh()->contentable_id);
        $this->assertSame(Thread::class, $content->fresh()->contentable_type);
    }

    public function testHtmlThreadContentIsSavedAndSanitized()
    {
        $thread = $this->createThread(['type' => 'html', 'content' => ['body' => '<p>Hello HTML</p><script>alert(1)</script>']]);
        $this->assertSame('<p>Hello HTML</p>', $thread->content->body);
        $this->assertNull($thread->content->markdown);
        $admin = \Database\Factories\UserFactory::new()->activated()->admin()->create();
        $payload = $thread->load('content')->toArray();
        $payload['pinned_at'] = now()->toDateTimeString();
        $this->actingAs($admin, 'api')->patchJson('/threads/'.$thread->id, $payload)->assertStatus(200);
        $this->assertSame('<p>Hello HTML</p>', $thread->fresh()->content->body);
    }

    public function testRegularUsersCannotChangeAnySingleModerationField()
    {
        $thread = $this->createThread();
        foreach (Thread::SENSITIVE_FIELDS as $field) {
            $this->patchJson('/threads/'.$thread->id, $this->threadPayload([$field => now()->toDateTimeString()]))
                ->assertStatus(403);
            $this->assertNull($thread->fresh()->{$field});
        }
    }

    public function testAdminModerationStillWorksWithTheFrontendPayload()
    {
        $thread = $this->createThread();
        $admin = \Database\Factories\UserFactory::new()->activated()->admin()->create();
        $payload = $thread->load('content')->toArray();
        $payload['pinned_at'] = now()->toDateTimeString();
        $this->actingAs($admin, 'api')->patchJson('/threads/'.$thread->id, $payload)->assertStatus(200);
        $this->assertNotNull($thread->fresh()->pinned_at);
        $this->assertSame('hello every one.', $thread->fresh()->content->markdown);
    }

    public function testPublicListsAndSearchExcludePrivateThreads()
    {
        $public = $this->createThread(['title' => 'Public matching post']);
        $draft = $this->createThread(['title' => 'Draft matching post', 'is_draft' => true]);
        $banned = $this->createThread(['title' => 'Banned matching post']);
        $future = $this->createThread(['title' => 'Future matching post']);
        $invalidAuthor = $this->createThread(['title' => 'Invalid author matching post']);
        $deleted = $this->createThread(['title' => 'Deleted matching post']);
        DB::table('threads')->where('id', $banned->id)->update(['banned_at' => now()]);
        DB::table('threads')->where('id', $future->id)->update(['published_at' => now()->addDay()]);
        DB::table('threads')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        DB::table('users')->where('id', $invalidAuthor->user_id)->update(['banned_at' => now()]);
        $this->asGuest();
        foreach ([$draft, $banned, $future, $invalidAuthor, $deleted] as $private) {
            $this->getJson('/threads/'.$private->id)->assertStatus(404);
        }
        $this->getJson('/threads')->assertStatus(200)->assertJsonCount(1, 'data');
        $response = $this->getJson('/threads/search?q=matching')->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($public->id, $response->json('data.0.id'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function testRagCanReadMoreThanSixtyPostsPerMinute()
    {
        $thread = $this->createThread();
        $this->asGuest();
        Carbon::setTestNow(now());
        try {
            for ($i = 0; $i < 75; ++$i) {
                $this->getJson('/threads/'.$thread->id)->assertStatus(200)
                    ->assertJsonFragment(['markdown' => 'hello every one.']);
            }
        } finally {
            Carbon::setTestNow();
        }
        $this->assertSame(75, $thread->fresh()->cache['views_count']);
    }

    public function testRemovingTheSharedQuotaKeepsPostingFrequencyAndCaptchaChecks()
    {
        $thread = $this->createThread();
        $this->postJson('/threads', $this->threadPayload(['title' => 'Another post without delay']))->assertStatus(403);
        $payload = $this->threadPayload(['title' => 'Post without verification']);
        unset($payload['ticket']);
        $this->postJson('/threads', $payload)->assertStatus(422)->assertJsonValidationErrors(['ticket']);
    }

    public function testFailedBodySaveRollsBackThreadChanges()
    {
        $thread = $this->createThread();
        $this->withoutExceptionHandling();
        Purifier::shouldReceive('clean')->once()->andThrow(new \RuntimeException('Simulated body save failure'));
        try {
            $this->patchJson('/threads/'.$thread->id, $this->threadPayload([
                'title' => 'Must not survive a failed write', 'content' => ['markdown' => 'Must not be saved'],
            ]));
            $this->fail('Expected the body write to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated body save failure', $exception->getMessage());
        }
        $this->assertSame('Hello world!', $thread->fresh()->title);
        $this->assertSame('hello every one.', $thread->fresh()->content->markdown);
    }

    public function testMentionJobsAreDispatchedAfterTheThreadTransaction()
    {
        $transactionLevel = DB::transactionLevel();
        Queue::shouldReceive('connection')->andReturnSelf();
        Queue::shouldReceive('push')->withArgs(function ($job) use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            if ($job instanceof FetchContentMentions) {
                $this->assertDatabaseHas('contents', ['markdown' => 'hello every one.']);
            }

            return true;
        })->once();
        $this->createThread();
    }

    public function testConcurrentViewSnapshotsDoNotLoseCountsOrOtherCacheFields()
    {
        $thread = $this->createThread();
        $this->assertNull(DB::table('threads')->where('id', $thread->id)->value('cache'));
        $thread->incrementViews();
        $this->assertSame(1, $thread->fresh()->cache['views_count']);
        DB::table('threads')->where('id', $thread->id)->update([
            'cache' => json_encode(['views_count' => 1, 'comments_count' => 7]),
        ]);
        $firstReader = $thread->fresh();
        $secondReader = $thread->fresh();
        $firstReader->incrementViews();
        $secondReader->incrementViews();
        $this->assertSame(3, $thread->fresh()->cache['views_count']);
        $this->assertSame(7, $thread->fresh()->cache['comments_count']);
    }
}
