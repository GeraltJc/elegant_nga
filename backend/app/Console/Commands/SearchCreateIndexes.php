<?php

namespace App\Console\Commands;

use App\Services\Search\ElasticsearchClientFactory;
use Illuminate\Console\Command;

class SearchCreateIndexes extends Command
{
    protected $signature = 'search:create-indexes {--fresh : 删除已有索引后重建}';

    protected $description = '创建 NGA 主题与回复 Elasticsearch 索引';

    public function handle(): int
    {
        $client = ElasticsearchClientFactory::make();

        $indexes = [
            config('elasticsearch.indexes.threads') => $this->threadIndexBody(),
            config('elasticsearch.indexes.posts') => $this->postIndexBody(),
        ];

        foreach ($indexes as $index => $body) {
            $exists = $client->indices()->exists(['index' => $index])->asBool();

            if ($exists && $this->option('fresh')) {
                $client->indices()->delete(['index' => $index]);
                $this->warn("Deleted index: {$index}");
                $exists = false;
            }

            if (! $exists) {
                $client->indices()->create([
                    'index' => $index,
                    'body' => $body,
                ]);

                $this->info("Created index: {$index}");
                continue;
            }

            $this->line("Index already exists: {$index}");
        }

        return self::SUCCESS;
    }

    private function threadIndexBody(): array
    {
        return [
            'settings' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
                'analysis' => [
                    'analyzer' => [
                        'default' => [
                            'type' => 'standard',
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'long'],
                    'source_thread_id' => ['type' => 'long'],
                    'title' => ['type' => 'text'],
                    'title_prefix_text' => ['type' => 'text'],
                    'author_name' => ['type' => 'keyword'],
                    'thread_created_at' => ['type' => 'date'],
                    'last_reply_at' => ['type' => 'date'],
                    'reply_count_display' => ['type' => 'integer'],
                    'view_count_display' => ['type' => 'integer'],
                    'is_pinned' => ['type' => 'boolean'],
                    'is_digest' => ['type' => 'boolean'],
                ],
            ],
        ];
    }

    private function postIndexBody(): array
    {
        return [
            'settings' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
                'analysis' => [
                    'analyzer' => [
                        'default' => [
                            'type' => 'standard',
                        ],
                    ],
                ],
            ],
            'mappings' => [
                'properties' => [
                    'id' => ['type' => 'long'],
                    'thread_id' => ['type' => 'long'],
                    'source_thread_id' => ['type' => 'long'],
                    'thread_title' => ['type' => 'text'],
                    'floor_number' => ['type' => 'integer'],
                    'author_name' => ['type' => 'keyword'],
                    'content_text' => ['type' => 'text'],
                    'post_created_at' => ['type' => 'date'],
                    'content_last_changed_at' => ['type' => 'date'],
                    'is_deleted_by_source' => ['type' => 'boolean'],
                    'is_folded_by_source' => ['type' => 'boolean'],
                ],
            ],
        ];
    }
}
