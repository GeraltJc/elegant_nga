<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Thread;
use App\Services\Nga\FixtureNgaLiteClient;
use App\Services\Nga\NgaLiteCrawler;
use App\Services\Nga\NgaLiteListParser;
use App\Services\Nga\NgaLitePayloadDecoder;
use App\Services\Nga\NgaLiteThreadParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgaLiteCrawlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_crawl_with_fixtures_persists_threads_and_posts(): void
    {
        $crawler = $this->makeCrawler();

        $result = $crawler->crawlForum(7, 5, null, 1);

        $this->assertSame(2, $result['threads']);
        $this->assertSame(3, $result['posts']);

        $this->assertSame(2, Thread::count());
        $this->assertSame(3, Post::count());

        $thread = Thread::where('source_thread_id', 1001)->firstOrFail();
        $this->assertSame('[water] First thread', $thread->title);
        $this->assertSame('water', $thread->title_prefix_text);
        $this->assertSame('Alice', $thread->author_name);

        $post = Post::where('source_post_id', 9001)->firstOrFail();
        $this->assertSame(1, $post->floor_number);
        $this->assertSame('[b]Hello[/b]', $post->content_html);
    }

    public function test_crawl_is_idempotent(): void
    {
        $crawler = $this->makeCrawler();

        $crawler->crawlForum(7, 5, null, 1);
        $crawler->crawlForum(7, 5, null, 1);

        $this->assertSame(2, Thread::count());
        $this->assertSame(3, Post::count());
    }

    private function makeCrawler(): NgaLiteCrawler
    {
        $fixturePath = base_path('tests/Fixtures/nga');
        $client = new FixtureNgaLiteClient($fixturePath);
        $decoder = new NgaLitePayloadDecoder();
        $listParser = new NgaLiteListParser($decoder);
        $threadParser = new NgaLiteThreadParser($decoder);

        return new NgaLiteCrawler($client, $listParser, $threadParser);
    }
}
