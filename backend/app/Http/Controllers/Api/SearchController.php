<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Search\NgaSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 搜索接口控制器。
 *
 * GET /api/search?q=救赎&type=all
 * GET /api/search?q=救赎&type=threads
 * GET /api/search?q=救赎buff&type=posts
 */
class SearchController extends Controller
{
    public function index(Request $request, NgaSearchService $searchService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'type' => ['sometimes', Rule::in(['all', 'threads', 'posts'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $keyword = trim((string) $validated['q']);
        $type = (string) ($validated['type'] ?? 'all');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);

        if ($type === 'threads') {
            return response()->json([
                'data' => [
                    'threads' => $searchService->searchThreads($keyword, $page, $perPage),
                ],
            ]);
        }

        if ($type === 'posts') {
            return response()->json([
                'data' => [
                    'posts' => $searchService->searchPosts($keyword, $page, $perPage),
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'threads' => $searchService->searchThreads($keyword, $page, $perPage),
                'posts' => $searchService->searchPosts($keyword, $page, $perPage),
            ],
        ]);
    }
}
