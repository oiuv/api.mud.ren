<?php

namespace Tests\Feature;

use App\Content;
use App\Thread;
use App\User;
use Tests\TestCase;

class ContentTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        $user = \factory(User::class)->states('activated')->create();

        $this->actingAs($user, 'api');
    }

    public function testHtmlBodyContentWillBeClean()
    {
        $content = Content::create([
            'contentable_id' => 1,
            'contentable_type' => Thread::class,
            'body' => '<h2>Hello mud.ren.</h2><br><p>Some text here.</p><script>alert("xss")</script>',
        ]);

        $this->assertSame('<h2>Hello mud.ren.</h2><br><p>Some text here.</p>', $content->body);
    }

    public function testMarkdownBodyWillBeTransToHtml()
    {
        $content = Content::create([
            'contentable_id' => 1,
            'contentable_type' => Thread::class,
            'markdown' => "##Hello mud.ren.\nSome text here.[some text](javascript:alert('xss'))",
        ]);

        $this->assertSame("<h2>Hello mud.ren.</h2>\n<p>Some text here.<a>some text</a></p>", $content->body);
    }

    public function testEmojiMarkdownContentWillBeTransToUnicode()
    {
        $content = Content::create([
            'contentable_id' => 1,
            'contentable_type' => Thread::class,
            'markdown' => "##Hello mud.ren.\nSome text here. :smile:",
        ]);

        $this->assertSame("<h2>Hello mud.ren.</h2>\n<p>Some text here. 😄</p>", $content->body);
    }
}
