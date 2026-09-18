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
