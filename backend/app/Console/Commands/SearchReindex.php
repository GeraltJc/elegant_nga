<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Thread;
use App\Services\Search\ElasticsearchClientFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SearchReindex extends Command
{
    protected $signature = 'search:reindex
        {--fresh : 删除已有索引后重建}
        {--chunk=500 : 每批导入数量}';

    protected $description = '将 MySQL 中的 NGA 主题与回复重建到 Elasticsearch';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        if ($this->option('fresh')) {
            $this->call('search:create-indexes', ['--fresh' => true]);
        } else {
            $this->call('search:create-indexes');
        }

        $this->indexThreads($chunk);
        $this->indexPosts($chunk);

        $this->info('Search reindex finished.');

        return self::SUCCESS;
    }

    private function indexThreads(int $chunk): void
    {
        $client = ElasticsearchClientFactory::make();
        $index = config('elasticsearch.indexes.threads');

        $total = Thread::query()->count();
        $this->info("Indexing threads: {$total}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Thread::query()
            ->orderBy('id')
            ->chunkById($chunk, function ($threads) use ($client, $index, $bar): void {
                $body = [];

                foreach ($threads as $thread) {
                    $body[] = [
                        'index' => [
                            '_index' => $index,
                            '_id' => (string) $thread->id,
                        ],
                    ];

                    $body[] = [
                        'id' => (int) $thread->id,
                        'source_thread_id' => (int) $thread->source_thread_id,
                        'title' => (string) $thread->title,
                        'title_prefix_text' => $thread->title_prefix_text,
                        'author_name' => (string) $thread->author_name,
                        'thread_created_at' => $thread->thread_created_at?->toISOString(),
                        'last_reply_at' => $thread->last_reply_at?->toISOString(),
                        'reply_count_display' => (int) $thread->reply_count_display,
                        'view_count_display' => $thread->view_count_display === null ? null : (int) $thread->view_count_display,
                        'is_pinned' => (bool) $thread->is_pinned,
                        'is_digest' => (bool) $thread->is_digest,
                    ];

                    $bar->advance();
                }

                $this->bulkIndex($client, $body);
            });

        $bar->finish();
        $this->newLine();
    }

    private function indexPosts(int $chunk): void
    {
        $client = ElasticsearchClientFactory::make();
        $index = config('elasticsearch.indexes.posts');

        $total = Post::query()->count();
        $this->info("Indexing posts: {$total}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Post::query()
            ->with('thread:id,source_thread_id,title')
            ->orderBy('id')
            ->chunkById($chunk, function ($posts) use ($client, $index, $bar): void {
                $body = [];

                foreach ($posts as $post) {
                    $body[] = [
                        'index' => [
                            '_index' => $index,
                            '_id' => (string) $post->id,
                        ],
                    ];

                    $body[] = [
                        'id' => (int) $post->id,
                        'thread_id' => (int) $post->thread_id,
                        'source_thread_id' => $post->thread?->source_thread_id === null
                            ? null
                            : (int) $post->thread->source_thread_id,
                        'thread_title' => (string) ($post->thread?->title ?? ''),
                        'floor_number' => (int) $post->floor_number,
                        'author_name' => (string) $post->author_name,
                        'content_text' => $this->contentHtmlToText((string) $post->content_html),
                        'post_created_at' => $post->post_created_at?->toISOString(),
                        'content_last_changed_at' => $post->content_last_changed_at?->toISOString(),
                        'is_deleted_by_source' => (bool) $post->is_deleted_by_source,
                        'is_folded_by_source' => (bool) $post->is_folded_by_source,
                    ];

                    $bar->advance();
                }

                $this->bulkIndex($client, $body);
            });

        $bar->finish();
        $this->newLine();
    }

    private function bulkIndex($client, array $body): void
    {
        if ($body === []) {
            return;
        }

        $response = $client->bulk(['body' => $body])->asArray();

        if (($response['errors'] ?? false) !== true) {
            return;
        }

        foreach ($response['items'] ?? [] as $item) {
            $error = $item['index']['error'] ?? null;

            if ($error !== null) {
                $this->error(json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                break;
            }
        }

        throw new \RuntimeException('Elasticsearch bulk index failed.');
    }

    private function contentHtmlToText(string $html): string
    {
        $text = str_replace(["<br>", "<br/>", "<br />"], "\n", $html);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 第一版：简单去掉常见 BBCode 标签，后续再按搜索效果优化。
        $text = preg_replace('/\[(\/)?(quote|b|i|u|s)\]/i', ' ', $text) ?? $text;
        $text = preg_replace('/\[(pid|uid|url|img|size|color)=[^\]]*\]/i', ' ', $text) ?? $text;
        $text = preg_replace('/\[\/(pid|uid|url|img|size|color)\]/i', ' ', $text) ?? $text;

        return Str::of($text)->squish()->toString();
    }
}
