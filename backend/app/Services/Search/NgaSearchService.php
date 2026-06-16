<?php

namespace App\Services\Search;

use Elastic\Elasticsearch\Response\Elasticsearch;
use Illuminate\Support\Arr;

/**
 * NGA Elasticsearch 搜索服务。
 *
 * 职责：
 * - 搜索主题索引 nga_threads
 * - 搜索回复索引 nga_posts
 * - 统一转换 Elasticsearch 返回结构
 */
class NgaSearchService
{
    public function searchThreads(string $keyword, int $page = 1, int $perPage = 10): array
    {
        $client = ElasticsearchClientFactory::make();

        $response = $client->search([
            'index' => config('elasticsearch.indexes.threads'),
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
            'body' => [
                'query' => [
                    'multi_match' => [
                        'query' => $keyword,
                        'fields' => [
                            'title^3',
                            'title_prefix_text',
                            'author_name',
                        ],
                    ],
                ],
                'highlight' => [
                    'pre_tags' => ['<mark>'],
                    'post_tags' => ['</mark>'],
                    'fields' => [
                        'title' => new \stdClass(),
                        'title_prefix_text' => new \stdClass(),
                    ],
                ],
            ],
        ]);

        return $this->formatResult($response, 'thread', $page, $perPage);
    }

    public function searchPosts(string $keyword, int $page = 1, int $perPage = 10): array
    {
        $client = ElasticsearchClientFactory::make();

        $response = $client->search([
            'index' => config('elasticsearch.indexes.posts'),
            'from' => ($page - 1) * $perPage,
            'size' => $perPage,
            'body' => [
                'query' => [
                    'multi_match' => [
                        'query' => $keyword,
                        'fields' => [
                            'content_text^3',
                            'thread_title^2',
                            'author_name',
                        ],
                    ],
                ],
                'highlight' => [
                    'pre_tags' => ['<mark>'],
                    'post_tags' => ['</mark>'],
                    'fields' => [
                        'content_text' => [
                            'fragment_size' => 120,
                            'number_of_fragments' => 3,
                        ],
                        'thread_title' => new \stdClass(),
                    ],
                ],
            ],
        ]);

        return $this->formatResult($response, 'post', $page, $perPage);
    }

    private function formatResult(Elasticsearch $response, string $type, int $page, int $perPage): array
    {
        $payload = $response->asArray();

        $total = (int) Arr::get($payload, 'hits.total.value', 0);
        $hits = Arr::get($payload, 'hits.hits', []);

        $data = array_map(function (array $hit) use ($type): array {
            return [
                'type' => $type,
                'id' => $hit['_id'] ?? null,
                'score' => $hit['_score'] ?? null,
                'source' => $hit['_source'] ?? [],
                'highlight' => $hit['highlight'] ?? [],
            ];
        }, $hits);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ];
    }
}
