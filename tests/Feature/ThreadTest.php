<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

class ThreadTest extends TestCase
{
    public function testCreateThreadWithoutLogin()
    {
        $this->postJson('/threads', $this->threadPayload())
            ->assertStatus(401);
    }

    public function testOnlyUserActivatedCanCreateThread()
    {
        $user = \Database\Factories\UserFactory::new()->create();
        $this->actingAs($user, 'api')->postJson('/threads', $this->threadPayload())
            ->assertStatus(403);

        // activated
        $userActivated = \Database\Factories\UserFactory::new()->activated()->create();
        $this->actingAs($userActivated, 'api')->postJson('/threads', $this->threadPayload())
            ->assertStatus(201);
    }

    /**
     * Only logged user can post threads.
     */
    public function testLoggedUserCanCreateThread()
    {
        $user = \Database\Factories\UserFactory::new()->activated()->create();

        $this->actingAs($user, 'api')->postJson('/threads', $this->threadPayload())
            ->assertStatus(201)
            ->assertJsonStructure(['title', 'user_id', 'content' => ['body']])
            ->assertJsonFragment(['title' => 'Hello world!', 'body' => '<p>hello every one.</p>']);

        $this->actingAs($user, 'api')->patchJson('/threads/1', [
            'title' => 'The New Title',
            'type' => 'markdown',
            'content' => ['markdown' => 'updated content.'],
            'ticket' => 'fake-test-ticket',
        ])->assertJsonFragment([
            'title' => 'The New Title',
            'body' => '<p>updated content.</p>',
        ]);
    }

    public function testUserCannotUpdateOtherUsersThread()
    {
        $user1 = \Database\Factories\UserFactory::new()->activated()->create();
        $user2 = \Database\Factories\UserFactory::new()->activated()->create();

        $this->actingAs($user1, 'api')->postJson('/threads', $this->threadPayload())
            ->assertStatus(201);

        $this->actingAs($user2, 'api')->patchJson('/threads/1', $this->threadPayload())
            ->assertForbidden();
    }

    public function testViewThread()
    {
        $user = \Database\Factories\UserFactory::new()->activated()->create();

        $this->actingAs($user, 'api')->postJson('/threads', $this->threadPayload())
            ->assertStatus(201)
            ->assertJsonStructure(['title', 'user_id', 'content' => ['body']])
            ->assertJsonFragment(['title' => 'Hello world!', 'body' => '<p>hello every one.</p>']);

        $this->get('/threads/1')->assertJsonFragment([
            'title' => 'Hello world!',
            'user_id' => 1,
            'body' => '<p>hello every one.</p>',
        ]);
    }

    public function testUserCanOnlyDeleteHisThread()
    {
        $user1 = \Database\Factories\UserFactory::new()->activated()->create();
        $user2 = \Database\Factories\UserFactory::new()->activated()->create();

        $this->actingAs($user1, 'api')->postJson('/threads', $this->threadPayload())
            ->assertStatus(201);

        // another user
        $this->actingAs($user2, 'api')->deleteJson('/threads/1')->assertForbidden();

        // author
        $this->actingAs($user1, 'api')->deleteJson('/threads/1')->assertStatus(204);
    }
}
