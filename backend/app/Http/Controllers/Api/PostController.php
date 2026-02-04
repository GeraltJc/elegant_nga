<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 楼层查询接口控制器：按 pid 查询楼层内容用于引用展示。
 */
class PostController extends Controller
{
    /**
     * 按 pid 查询楼层内容（用于 Reply to 引用展示）。
     *
     * @param Request $request 请求对象
     * @param int $sourceThreadId 主题 tid
     * @return JsonResponse
     * 副作用：读库。
     */
    public function quote(Request $request, int $sourceThreadId): JsonResponse
    {
        $validated = $request->validate([
            'pid' => ['required', 'integer', 'min:1'],
        ]);

        $pid = (int) $validated['pid'];

        $thread = Thread::query()
            ->where('source_thread_id', $sourceThreadId)
            ->first();

        if (!$thread) {
            return response()->json(['message' => 'thread_not_found'], 404);
        }

        // 业务规则：pid 在不同主题中可能重复，必须带 thread_id 过滤以避免串楼层。
        $post = Post::query()
            ->where('thread_id', $thread->id)
            ->where('source_post_id', $pid)
            ->first();

        if (!$post) {
            return response()->json(['message' => 'post_not_found'], 404);
        }

        return response()->json([
            'data' => [
                'source_thread_id' => (int) $thread->source_thread_id,
                'source_post_id' => (int) $post->source_post_id,
                'floor_number' => (int) $post->floor_number,
                'author_name' => (string) $post->author_name,
                'post_created_at' => $post->post_created_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'content_html' => (string) $post->content_html,
                'is_deleted_by_source' => (bool) $post->is_deleted_by_source,
                'is_folded_by_source' => (bool) $post->is_folded_by_source,
            ],
        ]);
    }
}
