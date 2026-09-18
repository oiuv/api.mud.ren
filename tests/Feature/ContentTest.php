<?php

namespace Tests\Feature;

use App\Content;
use App\Thread;
use Tests\TestCase;

class ContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $user = \Database\Factories\UserFactory::new()->activated()->create();

        $this->actingAs($user, 'api');
    }

    public function testHtmlBodyContentWillBeClean()
    {
        $content = Content::create([
            'contentable_id' => 1,
            'contentable_type' => Thread::class,
            'body' => '<h2>Hello mud.ren.</h2><br><p>Some text here.</p><script>alert("xss")</script>',
        ]);

        $this->assertSame('<h2>Hello mud.ren.</h2><br><p>Some text here.</p>', str_replace("\r\n", "\n", $content->body));
    }

    public function testMarkdownBodyWillBeTransToHtml()
    {
        $content = Content::create([
            'contentable_id' => 1,
            'contentable_type' => Thread::class,
            'markdown' => "## Hello mud.ren.\nSome text here.[some text](javascript:alert('xss'))",
        ]);

        $this->assertSame("<h2>Hello mud.ren.</h2>\n<p>Some text here.<a>some text</a></p>", str_replace("\r\n", "\n", $content->body));
    }

    public function testEmojiMarkdownContentWillBeTransToUnicode()
    {
        $content = Content::create([
            'contentable_id' => 1,
            'contentable_type' => Thread::class,
            'markdown' => "## Hello mud.ren.\nSome text here. :smile:",
        ]);

        $this->assertSame("<h2>Hello mud.ren.</h2>\n<p>Some text here. 😄</p>", str_replace("\r\n", "\n", $content->body));
    }
}
