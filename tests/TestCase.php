<?php

namespace Tests;

use App\Node;
use App\Validators\TicketValidator;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use RefreshDatabase;

    protected $faker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faker = Faker::create();
        Queue::fake();
        Mail::fake();
        $this->instance(TicketValidator::class, \Mockery::mock(TicketValidator::class)
            ->shouldReceive('validate')->andReturn(true)->getMock());
        Node::create(['id' => 1, 'title' => 'Testing node']);
    }

    protected function threadPayload(array $attributes = [])
    {
        return array_merge([
            'title' => 'Hello world!',
            'node_id' => 1,
            'type' => 'markdown',
            'content' => ['markdown' => 'hello every one.'],
            'ticket' => 'fake-test-ticket',
        ], $attributes);
    }
}
