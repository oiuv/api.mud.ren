<?php

namespace Tests\Feature;

use App\Thread;
use Tests\TestCase;

class CommentTest extends TestCase
{
    public function testOnlyUserActivatedCanCreateThread()
    {
        $user = \Database\Factories\UserFactory::new()->activated()->create();

        $this->actingAs($user, 'api')->postJson('/threads', [
            'title' => 'Hello world!',
            'node_id' => 1,
            'type' => 'markdown',
            'content' => ['markdown' => 'hello every one.'],
            'ticket' => 'fake-test-ticket',
        ])
            ->assertStatus(201);

        // activated
        $this->actingAs($user, 'api')
            ->postJson('/comments', [
                'commentable_type' => Thread::class,
                'commentable_id' => 1,
                'type' => 'markdown',
                'content' => ['markdown' => 'Very Good!'],
            ])
            ->assertStatus(201);

        // not activated
        $user = \Database\Factories\UserFactory::new()->create();

        $this->actingAs($user, 'api')->postJson('/comments', [
            'commentable_type' => Thread::class,
            'commentable_id' => 1,
            'type' => 'markdown',
            'content' => ['markdown' => 'Very Good!'],
        ])->assertStatus(403);
    }

    public function testUserCanCommentThread()
    {
        $user = \Database\Factories\UserFactory::new()->activated()->create();

        $this->actingAs($user, 'api')->postJson('/threads', [
            'title' => 'Hello world!',
            'node_id' => 1,
            'type' => 'markdown',
            'content' => ['markdown' => 'hello every one.'],
            'ticket' => 'fake-test-ticket',
        ])
            ->assertStatus(201);

        $this->postJson('/comments', [
            'commentable_type' => Thread::class,
            'commentable_id' => 1,
            'type' => 'markdown',
            'content' => ['markdown' => 'Very Good!'],
        ])
            ->assertStatus(201)
            ->assertJsonFragment([
                'user_id' => 1,
                'commentable_type' => Thread::class,
                'commentable_id' => 1,
                'body' => '<p>Very Good!</p>',
            ]);
    }

    public function testUserCannotCommentInvalidObject()
    {
        $user = \Database\Factories\UserFactory::new()->activated()->create();

        $this->actingAs($user, 'api')
                ->postJson('/comments', [
                    'commentable_type' => 'App\\ClassNotExists',
                    'commentable_id' => 1,
                    'type' => 'markdown',
                    'content' => ['markdown' => 'Very Good!'],
                ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commentable_id']);
    }
}
