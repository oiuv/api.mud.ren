<?php

namespace Tests\Feature;

use App\Mail\Activation;
use App\Mail\ResetPassword;
use App\Mail\Transport\AliyunTransport;
use App\Thread;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RuntimeCompatibilityTest extends TestCase
{
    public function testLocalImageUploadKeepsTheFrontendResponseAndRequiresLogin()
    {
        Storage::fake('public');
        $this->postJson('/files/upload')->assertUnauthorized();
        $user = UserFactory::new()->activated()->create();
        $response = $this->actingAs($user, 'api')->postJson('/files/upload', [
            'file' => UploadedFile::fake()->image('forum.png'),
        ])->assertOk()->assertJsonStructure(['path', 'url', 'location', 'filename', 'disk']);
        Storage::disk('public')->assertExists($response->json('path'));
        $this->assertSame($response->json('url'), $response->json('location'));
        $this->postJson('/files/upload', ['file' => UploadedFile::fake()->create('payload.php', 1, 'text/x-php')])
            ->assertStatus(422);
    }

    public function testDefaultAvatarCanBeGeneratedAndSaved()
    {
        Storage::fake('public');
        $user = UserFactory::new()->create(['avatar' => null]);
        $this->assertStringContainsString('/storage/', $user->avatar);
        $this->assertNotEmpty(Storage::disk('public')->allFiles());
    }

    public function testMailTemplatesRenderAndAliyunTransportIsRegistered()
    {
        $user = UserFactory::new()->create();
        $this->assertStringContainsString('activate', (new Activation($user))->render());
        $this->assertStringContainsString('reset-password', (new ResetPassword($user->email, 'test-token'))->render());
        $this->assertInstanceOf(AliyunTransport::class, $this->mailManager->mailer('directmail')->getSymfonyTransport());
    }

    public function testLegacySmtpEncryptionSettingsRemainEnforced()
    {
        $previousEnv = $_ENV;
        $previousServer = $_SERVER;
        $previousProcess = [];
        foreach (['MAIL_ENCRYPTION', 'MAIL_REQUIRE_TLS', 'MAIL_SCHEME'] as $key) {
            $previousProcess[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        try {
            foreach (['tls', 'ssl'] as $encryption) {
                putenv('MAIL_ENCRYPTION='.$encryption);
                $_ENV['MAIL_ENCRYPTION'] = $_SERVER['MAIL_ENCRYPTION'] = $encryption;
                $mailConfig = require config_path('mail.php');
                config(['mail.mailers.smtp' => $mailConfig['mailers']['smtp']]);
                $this->mailManager->purge('smtp');
                $transport = $this->mailManager->mailer('smtp')->getSymfonyTransport();
                if ($encryption === 'tls') {
                    $this->assertTrue($transport->isTlsRequired(), 'Legacy STARTTLS must not fall back to plaintext.');
                } else {
                    $this->assertTrue($transport->getStream()->isTLS(), 'Legacy SSL must still use implicit TLS.');
                }
            }
        } finally {
            foreach ($previousProcess as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
            $_ENV = $previousEnv;
            $_SERVER = $previousServer;
        }
    }

    public function testRelationEndpointPreservesLikesSubscriptionsAndActivities()
    {
        $owner = UserFactory::new()->activated()->create();
        $post = $this->actingAs($owner, 'api')->postJson('/threads', $this->threadPayload())->assertCreated();
        $thread = Thread::findOrFail($post->json('id'));
        $reader = UserFactory::new()->activated()->create();
        foreach (['like', 'subscribe', 'favorite'] as $relation) {
            $payload = ['followable_id' => $thread->id, 'followable_type' => Thread::class];
            $this->actingAs($reader, 'api')->postJson('/relations/'.$relation, $payload)->assertNoContent();
            $this->assertDatabaseHas('followables', [
                'user_id' => $reader->id, 'followable_id' => $thread->id, 'relation' => $relation,
            ]);
            $this->assertDatabaseHas('activity_log', ['log_name' => $relation.'.thread', 'causer_id' => $reader->id]);
            $this->postJson('/relations/'.$relation, $payload)->assertNoContent();
            $this->assertDatabaseMissing('followables', [
                'user_id' => $reader->id, 'followable_id' => $thread->id, 'relation' => $relation,
            ]);
        }
        $this->assertDatabaseMissing('activity_log', ['log_name' => 'like.thread', 'causer_id' => $reader->id]);
    }

    public function testCorsStillPermitsTheFrontendRequest()
    {
        // Production IIS supplies CORS headers; verify the built-in alternative independently.
        config(['cors.paths' => ['*']]);
        $this->options('/threads/search', [], [
            'Origin' => 'https://bbs.mud.ren', 'Access-Control-Request-Method' => 'GET',
        ])->assertSuccessful()->assertHeader('Access-Control-Allow-Origin');
    }
}
