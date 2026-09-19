<?php

namespace Tests\Feature;

use App\Thread;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThreadSearchTest extends TestCase
{
    private function createPost(string $title, string $body = '普通正文', string $type = 'markdown'): Thread
    {
        $user = \Database\Factories\UserFactory::new()->activated()->create();
        $response = $this->actingAs($user, 'api')->postJson('/threads', $this->threadPayload([
            'title' => $title, 'type' => $type,
            'content' => [$type === 'html' ? 'body' : 'markdown' => $body],
        ]))->assertStatus(201);

        return Thread::findOrFail($response->json('id'));
    }

    private function search(string $query, int $page = 1)
    {
        return $this->getJson('/threads/search?'.http_build_query(['q' => $query, 'page' => $page]))->assertStatus(200);
    }

    public function testChineseTitleAndBodySearchKeepsTheFrontendContract()
    {
        $title = $this->createPost('武侠论坛新手指南');
        $body = $this->createPost('这里标题不含关键词', '欢迎来到武侠世界');
        $html = $this->createPost('这是富文本主题标题', '<p>武侠世界</p>', 'html');
        $response = $this->search(' 武侠 ')->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);
        $this->assertSame($title->id, $response->json('data.0.id'));
        $this->assertSame([$title->id, $html->id, $body->id], array_column($response->json('data'), 'id'));
        $this->assertSame('<em>武侠</em>论坛新手指南', $response->json('data.0.highlights.title.0'));
        $this->search('侠')->assertJsonCount(3, 'data');
        $this->getJson('/threads/search?query='.urlencode('武侠'))->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function testSpecialCharactersAreLiteralAndCannotInjectSql()
    {
        $this->createPost('讨论百分号和下划线', '价格100% 完成_a ! \\ 和 SQL');
        $this->createPost('这里是另外一篇主题', '价格1000 完成Xa');
        foreach (['%', '_', '!', '\\'] as $term) {
            $this->search($term)->assertJsonCount(1, 'data');
        }
        $this->search("' OR 1=1 --")->assertJsonCount(0, 'data');
    }

    public function testEmptyAndInvalidQueries()
    {
        $this->createPost('不会在空查询时列出');
        $this->search('   ')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/threads/search')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson('/threads/search?q[]=x')->assertStatus(422);
        $this->getJson('/threads/search?q='.str_repeat('x', 101))->assertStatus(422);
    }

    public function testPaginationPrioritizesTitlesAndOrdersEqualDatesById()
    {
        $ids = [];
        for ($i = 0; $i < 12; ++$i) {
            $ids[] = $this->createPost('分页检索主题编号'.$i)->id;
        }
        $body = $this->createPost('正文包含目标词语', '分页检索');
        DB::table('threads')->update(['published_at' => now()->subDay()->startOfDay()]);
        $first = $this->search('分页检索')->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 13);
        $second = $this->search('分页检索', 2)->assertJsonCount(3, 'data');
        $this->assertSame(array_reverse($ids), array_slice(array_merge(
            array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')
        ), 0, 12));
        $this->assertSame($body->id, $second->json('data.2.id'));
        $this->assertStringContainsString('q=', $first->json('links.next'));
    }

    public function testHighlightsEscapeHtmlIncludingUnmatchedTitles()
    {
        $thread = $this->createPost('安全高亮测试主题', '匹配词 <script>alert(1)</script>');
        DB::table('threads')->where('id', $thread->id)->update(['title' => '<img src=x onerror=alert(1)>']);
        $result = $this->search('匹配词')->json('data.0.highlights');
        $this->assertStringContainsString('&lt;img', $result['title'][0]);
        $this->assertStringNotContainsString('<img', $result['title'][0]);
        $this->assertStringContainsString('<em>匹配词</em>', $result['content'][0]);
        $this->assertStringNotContainsString('<script>', $result['content'][0]);
        $this->assertStringContainsString('<em>&lt;img</em>', $this->search('<img')->json('data.0.highlights.title.0'));
    }

    public function testEditsAreImmediatelySearchableAndDeletedBodiesAreExcluded()
    {
        $thread = $this->createPost('内容修改即时生效', '修改之前的关键词');
        $this->search('修改之前')->assertJsonCount(1, 'data');
        $this->patchJson('/threads/'.$thread->id, $this->threadPayload([
            'title' => $thread->title, 'content' => ['markdown' => '修改之后的关键词'],
        ]))->assertStatus(200);
        $this->search('修改之前')->assertJsonCount(0, 'data');
        $this->search('修改之后')->assertJsonCount(1, 'data');
        DB::table('contents')->where('contentable_id', $thread->id)->update(['deleted_at' => now()]);
        $this->search('修改之后')->assertJsonCount(0, 'data');
    }

    public function testHtmlTagsAndAttributesAreNotSearchableBodyText()
    {
        $this->createPost('包含链接的 Markdown 帖子', '[文档](https://example.test)');
        $this->createPost('包含链接的富文本帖子', '<p><a href="https://example.test">文档</a></p>', 'html');
        $this->search('href')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->search('文档')->assertJsonCount(2, 'data');

        $visible = $this->createPost('正文讨论链接属性', '说明 href 属性的用途');
        $this->search('href')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
    }

    public function testRichTextSearchDecodesEntitiesAndKeepsHighlightsSafe()
    {
        $thread = $this->createPost('富文本实体检索主题', '<p>初始正文</p>', 'html');
        // Cover stored entities without depending on the editor/purifier's serialization choices.
        DB::table('contents')->where('contentable_type', Thread::class)->where('contentable_id', $thread->id)->update([
            'body' => '<p>C++ &amp; LPC；&#x6B66;&#20384;；Soc<strong>ket</strong>；&lt;script&gt;；100% _ ! &#92;</p>',
        ]);
        foreach (['C++ & LPC', '武侠', 'socket', '<script>', '%', '_', '!', '\\'] as $term) {
            $response = $this->search($term)->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
            $highlight = $response->json('data.0.highlights.content.0');
            $this->assertStringContainsString('<em>', $highlight);
            $this->assertStringNotContainsString('<script>', $highlight);
            $this->assertStringNotContainsString('<strong>', $highlight);
        }
        $this->assertStringContainsString('<em>C++ &amp; LPC</em>', $this->search('C++ & LPC')->json('data.0.highlights.content.0'));
        $this->search('amp')->assertJsonPath('meta.total', 0);
    }

    public function testRichTextFilteringHappensBeforePaginationAndCounting()
    {
        $ids = [];
        for ($i = 0; $i < 12; ++$i) {
            $ids[] = $this->createPost('富文本正文命中编号'.$i, '<p>Soc<strong>ket</strong> 通信</p>', 'html')->id;
        }
        $title = $this->createPost('Socket 标题命中优先');
        for ($i = 0; $i < 12; ++$i) {
            $this->createPost('仅链接属性包含关键词'.$i, '<a href="https://example.test/socket">普通链接</a>', 'html');
        }
        DB::table('threads')->update(['published_at' => now()->subDay()->startOfDay()]);

        $first = $this->search('socket')->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 13);
        $second = $this->search('socket', 2)->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 13);
        $this->assertSame(array_merge([$title->id], array_reverse($ids)), array_merge(
            array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')
        ));
    }

    public function testRichTextEditsAndContentTypeChangesAreImmediatelySearchable()
    {
        $thread = $this->createPost('切换正文格式的主题', '<p>C++ &amp; LPC</p>', 'html');
        $this->search('C++ & LPC')->assertJsonPath('meta.total', 1);
        $this->patchJson('/threads/'.$thread->id, $this->threadPayload([
            'title' => $thread->title, 'type' => 'html', 'content' => ['body' => '<p>Socket 通信</p>'],
        ]))->assertStatus(200);
        $this->search('C++ & LPC')->assertJsonPath('meta.total', 0);
        $this->search('Socket')->assertJsonPath('meta.total', 1);

        $this->patchJson('/threads/'.$thread->id, $this->threadPayload([
            'title' => $thread->title, 'content' => ['markdown' => 'Markdown 检索词'],
        ]))->assertStatus(200);
        $this->search('Socket')->assertJsonPath('meta.total', 0);
        $this->search('Markdown')->assertJsonPath('meta.total', 1);

        $this->patchJson('/threads/'.$thread->id, $this->threadPayload([
            'title' => $thread->title, 'type' => 'html', 'content' => ['body' => '<p>富文本最终检索词</p>'],
        ]))->assertStatus(200);
        $this->search('Markdown')->assertJsonPath('meta.total', 0);
        $this->search('富文本最终')->assertJsonPath('meta.total', 1);
    }

    public function testRichTextSearchExcludesPrivateThreadsAndDeletedContent()
    {
        $public = $this->createPost('公开可检索主题标题', '<p>C++ &amp; LPC</p>', 'html');
        foreach ([
            ['threads', ['published_at' => null]],
            ['threads', ['published_at' => now()->addDay()]],
            ['threads', ['banned_at' => now()]],
            ['threads', ['deleted_at' => now()]],
            ['users', ['activated_at' => null]],
            ['users', ['banned_at' => now()]],
            ['contents', ['deleted_at' => now()]],
        ] as [$table, $attributes]) {
            $thread = $this->createPost('不可检索的富文本主题', '<p>C++ &amp; LPC</p>', 'html');
            $id = $table === 'users' ? $thread->user_id : ($table === 'contents' ? $thread->content->id : $thread->id);
            DB::table($table)->where('id', $id)->update($attributes);
        }
        DB::table('contents')->insert([
            'contentable_id' => $public->id, 'contentable_type' => 'App\\Comment',
            'markdown' => null, 'body' => '<p>评论特有词 &amp; LPC</p>',
        ]);
        $this->search('C++ & LPC')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $public->id);
        $this->search('评论特有词 & LPC')->assertJsonPath('meta.total', 0);
    }

    public function testZeroMarkdownIsNotReplacedByItsHtmlBody()
    {
        $thread = $this->createPost('零值正文检索主题', '初始正文');
        DB::table('contents')->where('contentable_type', Thread::class)->where('contentable_id', $thread->id)->update([
            'markdown' => '0', 'body' => '<p>只存在于渲染副本</p>',
        ]);
        $this->search('渲染副本')->assertJsonPath('meta.total', 0);
        $this->search('0')->assertJsonPath('data.0.highlights.content.0', '<em>0</em>');
    }

    public function testUnactivatedAuthorsAndCommentsAreNotSearchable()
    {
        $thread = $this->createPost('作者未激活关键词');
        DB::table('users')->where('id', $thread->user_id)->update(['activated_at' => null]);
        $this->search('未激活')->assertJsonCount(0, 'data');
        $public = $this->createPost('可以被正常读取主题');
        DB::table('contents')->insert([
            'contentable_id' => $public->id, 'contentable_type' => 'App\\Comment',
            'markdown' => '只在评论出现', 'body' => '只在评论出现',
        ]);
        $this->search('只在评论')->assertJsonCount(0, 'data');
    }
}
