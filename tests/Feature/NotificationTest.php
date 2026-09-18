<?php

namespace Tests\Feature;

use App\Notifications\Welcome;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function testUserCanGetNotifications()
    {
        $user = \Database\Factories\UserFactory::new()->create();

        $user->notifyNow(new Welcome());

        // not logged
        $this->getJson('/notifications')->assertStatus(401);

        // logged
        $notifications = json_decode($this->actingAs($user, 'api')->getJson('/notifications')
            ->assertStatus(200)
            ->getContent(), true);

        $this->assertCount(1, $notifications);
        $this->assertSame($user->id, $notifications[0]['data']['user_id']);
    }

    public function testUserCanMarkNotificationAsRead()
    {
        $user = \Database\Factories\UserFactory::new()->create();

        $user->notifyNow(new Welcome());

        $notifications = json_decode($this->actingAs($user, 'api')->getJson('/notifications')
            ->assertStatus(200)
            ->getContent(), true);
        $first = $notifications[0];

        $this->patchJson('/notifications/'.$first['id'])->assertStatus(200);
        $notifications = json_decode($this->actingAs($user, 'api')->getJson('/notifications')
            ->assertStatus(200)
            ->getContent(), true);
        $first = $notifications[0];

        $this->assertNotNull($first['read_at']);
    }

    public function testUserCanMarkAllNotificationAsRead()
    {
        $user = \Database\Factories\UserFactory::new()->create();

        $user->notifyNow(new Welcome());
        $user->notifyNow(new Welcome());

        $this->actingAs($user, 'api')->postJson('/notifications/mark-all-as-read')
                ->assertStatus(200);

        $notifications = json_decode($this->actingAs($user, 'api')->getJson('/notifications')
            ->assertStatus(200)
            ->getContent(), true);

        $this->assertNotNull($notifications[0]['read_at']);
        $this->assertNotNull($notifications[1]['read_at']);
    }
}
