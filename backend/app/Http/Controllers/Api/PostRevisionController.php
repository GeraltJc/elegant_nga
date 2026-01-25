<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 楼层历史版本接口，负责返回指定楼层的变更快照列表。
 */
class PostRevisionController extends Controller
{
    /**
     * 获取楼层历史版本列表（按时间倒序）。
     *
     * @param Request $request 请求对象
     * @param int $postId 楼层主键
     * @return JsonResponse
     * 副作用：读库。
     */
    public function index(Request $request, int $postId): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 5);

        $post = Post::query()->select('id')->whereKey($postId)->first();
        if (!$post) {
            return response()->json(['message' => 'post_not_found'], 404);
        }

        $paginator = PostRevision::query()
            ->where('post_id', $post->id)
            ->orderByDesc('revision_created_at')
            ->paginate($perPage, page: $page);

        $data = array_map(function (PostRevision $revision): array {
            return [
                'revision_created_at' => $revision->revision_created_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'source_edited_at' => $revision->source_edited_at?->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s'),
                'content_html' => (string) $revision->content_html,
                'change_detected_reason' => (string) $revision->change_detected_reason,
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
