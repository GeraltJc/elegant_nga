<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 主题相关接口控制器，负责列表、详情与楼层查询。
 */
class ThreadController extends Controller
{
    /**
     * 获取主题列表（支持分页、排序与搜索）。
     *
     * @param Request $request 请求对象
     * @return JsonResponse
     * 副作用：读库。
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(['created_at', 'last_reply_at'])],
            'q' => ['sometimes', 'string', 'max:200'],
            'reply_min' => ['sometimes', 'integer', 'min:0'],
            'reply_max' => ['sometimes', 'integer', 'min:0', 'gte:reply_min'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 30);
        $sort = (string) ($validated['sort'] ?? 'created_at');
        $keyword = trim((string) ($validated['q'] ?? ''));
        $replyMin = array_key_exists('reply_min', $validated) ? (int) $validated['reply_min'] : null;
        $replyMax = array_key_exists('reply_max', $validated) ? (int) $validated['reply_max'] : null;

        $query = Thread::query()
            ->select([
                'source_thread_id',
                'title',
                'title_prefix_text',
                'author_name',
                'thread_created_at',
                'last_reply_at',
                'reply_count_display',
                'view_count_display',
                'is_pinned',
                'is_digest',
                'is_truncated_by_page_limit',
                'truncated_at_page_number',
                'is_skipped_by_page_total_limit',
                'last_crawled_at',
            ]);

        if ($keyword !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword).'%';

            $query->where(function (Builder $searchQuery) use ($like): void {
                $searchQuery->where('title', 'like', $like)
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->selectRaw('1')
                            ->from('posts')
                            ->whereColumn('posts.thread_id', 'threads.id')
                            ->where('posts.floor_number', 0)
                            ->where('posts.content_html', 'like', $like);
                    });
            });
        }

        if ($replyMin !== null) {
            $query->where('reply_count_display', '>=', $replyMin);
        }

        if ($replyMax !== null) {
            $query->where('reply_count_display', '<=', $replyMax);
        }

        $query->orderByDesc('is_pinned');

        if ($sort === 'last_reply_at') {
            $query->orderByRaw('last_reply_at IS NULL asc')->orderByDesc('last_reply_at');
        } else {
            $query->orderByDesc('thread_created_at');
        }

        $paginator = $query->paginate($perPage, page: $page);

        $data = array_map(function (Thread $thread): array {
            return [
                'source_thread_id' => (int) $thread->source_thread_id,
                'title' => (string) $thread->title,
                'title_prefix_text' => $thread->title_prefix_text,
                'author_name' => (string) $thread->author_name,
                'thread_created_at' => $thread->thread_created_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'last_reply_at' => $thread->last_reply_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'reply_count_display' => (int) $thread->reply_count_display,
                'view_count_display' => $thread->view_count_display === null ? null : (int) $thread->view_count_display,
                'is_pinned' => (bool) $thread->is_pinned,
                'is_digest' => (bool) $thread->is_digest,
                'is_truncated_by_page_limit' => (bool) $thread->is_truncated_by_page_limit,
                'truncated_at_page_number' => $thread->truncated_at_page_number === null ? null : (int) $thread->truncated_at_page_number,
                'is_skipped_by_page_total_limit' => (bool) $thread->is_skipped_by_page_total_limit,
                'last_crawled_at' => $thread->last_crawled_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            ];
        }, $paginator->items());

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 获取主题详情（不包含楼层列表）。
     *
     * @param int $sourceThreadId 主题 tid
     * @return JsonResponse
     * 副作用：读库。
     */
    public function show(int $sourceThreadId): JsonResponse
    {
        $thread = Thread::query()
            ->where('source_thread_id', $sourceThreadId)
            ->first();

        if (!$thread) {
            return response()->json(['message' => 'thread_not_found'], 404);
        }

        return response()->json([
            'data' => [
                'source_thread_id' => (int) $thread->source_thread_id,
                'title' => (string) $thread->title,
                'title_prefix_text' => $thread->title_prefix_text,
                'author_name' => (string) $thread->author_name,
                'thread_created_at' => $thread->thread_created_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'last_reply_at' => $thread->last_reply_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'reply_count_display' => (int) $thread->reply_count_display,
                'view_count_display' => $thread->view_count_display === null ? null : (int) $thread->view_count_display,
                'is_pinned' => (bool) $thread->is_pinned,
                'is_digest' => (bool) $thread->is_digest,
                'is_truncated_by_page_limit' => (bool) $thread->is_truncated_by_page_limit,
                'truncated_at_page_number' => $thread->truncated_at_page_number === null ? null : (int) $thread->truncated_at_page_number,
                'is_skipped_by_page_total_limit' => (bool) $thread->is_skipped_by_page_total_limit,
                'last_crawled_at' => $thread->last_crawled_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * 获取指定主题的楼层列表（带分页）。
     *
     * @param Request $request 请求对象
     * @param int $sourceThreadId 主题 tid
     * @return JsonResponse
     * 副作用：读库。
     */
    public function posts(Request $request, int $sourceThreadId): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 30);

        $thread = Thread::query()
            ->where('source_thread_id', $sourceThreadId)
            ->first();

        if (!$thread) {
            return response()->json(['message' => 'thread_not_found'], 404);
        }

        $paginator = Post::query()
            ->where('thread_id', $thread->id)
            ->withCount('revisions')
            ->orderBy('floor_number')
            ->paginate($perPage, page: $page);

        $data = array_map(function (Post $post): array {
            return [
                'post_id' => (int) $post->id,
                'floor_number' => (int) $post->floor_number,
                'author_name' => (string) $post->author_name,
                'post_created_at' => $post->post_created_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'content_html' => (string) $post->content_html,
                'is_deleted_by_source' => (bool) $post->is_deleted_by_source,
                'is_folded_by_source' => (bool) $post->is_folded_by_source,
                'content_last_changed_at' => $post->content_last_changed_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'revision_count' => isset($post->revisions_count) ? (int) $post->revisions_count : 0,
            ];
        }, $paginator->items());

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }
}
