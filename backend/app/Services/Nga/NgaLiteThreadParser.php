<?php

namespace App\Services\Nga;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

class NgaLiteThreadParser
{
    public function __construct(private readonly NgaLitePayloadDecoder $decoder)
    {
    }

    /**
     * @return array{source_thread_id:int, page:int, page_total:int, posts:array<int, array<string, mixed>>}
     */
    public function parse(string $raw): array
    {
        if ($this->looksLikeHtml($raw)) {
            return $this->parseHtml($raw);
        }

        return $this->parseJson($raw);
    }

    /**
     * @return array{source_thread_id:int, page:int, page_total:int, posts:array<int, array<string, mixed>>}
     */
    private function parseJson(string $raw): array
    {
        $payload = $this->decoder->decode($raw);
        $posts = $payload['posts']
            ?? $payload['data']['posts']
            ?? $payload['p']
            ?? $payload['data']['p']
            ?? $payload['data']['__R']
            ?? $payload['__R']
            ?? null;

        if (!is_array($posts)) {
            throw new RuntimeException('Lite thread payload missing posts');
        }

        $page = $this->intValue($payload['data'] ?? $payload, ['page', 'page_no', 'page_number', '__PAGE'], 1);
        $pageTotal = $this->intValue($payload, ['page_total', 'page_count', 'total_page', 'total_pages'], null);
        $rowCount = $this->intValue($payload['data'] ?? $payload, ['__R__ROWS'], null);
        $rowPerPage = $this->intValue($payload['data'] ?? $payload, ['__R__ROWS_PAGE'], null);
        if ($pageTotal === null && $rowCount !== null && $rowPerPage) {
            $pageTotal = (int) ceil($rowCount / $rowPerPage);
        }
        $pageTotal = $pageTotal ?? 1;

        $sourceThreadId = $this->intValue($payload['data'] ?? $payload, ['tid', 'thread_id', 'id'], 0);
        $threadMeta = $payload['data']['__T'] ?? $payload['__T'] ?? [];
        if (!$sourceThreadId && is_array($threadMeta)) {
            $sourceThreadId = $this->intValue($threadMeta, ['tid', 'thread_id', 'id'], 0);
        }

        $users = $payload['data']['__U'] ?? $payload['__U'] ?? [];

        $resultPosts = [];
        foreach (array_values($posts) as $post) {
            if (!is_array($post)) {
                continue;
            }

            $authorId = $this->intValue($post, ['author_id', 'author_uid', 'author_source_user_id', 'authorid'], null);
            $authorName = $this->stringValue($post, ['author', 'author_name', 'poster']);
            if (!$authorName && $authorId !== null && isset($users[$authorId]) && is_array($users[$authorId])) {
                $authorName = $this->stringValue($users[$authorId], ['nickname', 'username']);
            }

            $floor = $this->intValue($post, ['floor', 'floor_number'], null);
            if ($floor === null && array_key_exists('lou', $post)) {
                $floor = ((int) $post['lou']) + 1;
            }

            $sourcePostId = $this->intValue($post, ['pid', 'post_id', 'source_post_id'], 0);
            if ($sourcePostId <= 0 && is_array($threadMeta)) {
                $tpid = $this->intValue($threadMeta, ['tpid'], 0);
                if ($tpid > 0) {
                    $sourcePostId = $tpid;
                }
            }

            $resultPosts[] = [
                'source_post_id' => $sourcePostId,
                'floor_number' => $floor ?? 0,
                'author_name' => $authorName,
                'author_source_user_id' => $authorId,
                'post_created_at' => $this->dateValue($post, ['postdatetimestamp', 'post_time', 'post_created_at', 'created_at', 'post_at', 'postdate']),
                'content_raw' => $this->stringValue($post, ['content', 'content_raw', 'content_html', 'message']),
                'content_format' => 'ubb',
                'is_deleted_by_source' => $this->boolValue($post, ['is_deleted', 'is_deleted_by_source', 'deleted'], false),
                'is_folded_by_source' => $this->boolValue($post, ['is_folded', 'is_folded_by_source', 'folded'], false),
                'source_edited_at' => $this->parseEditedAt($post),
            ];
        }

        return [
            'source_thread_id' => $sourceThreadId,
            'page' => $page,
            'page_total' => $pageTotal,
            'posts' => $resultPosts,
        ];
    }

    /**
     * @return array{source_thread_id:int, page:int, page_total:int, posts:array<int, array<string, mixed>>}
     */
    private function parseHtml(string $raw): array
    {
        // 访客 HTML 页面需要先做编码转换再解析 DOM
        $html = $this->normalizeHtmlEncoding($raw);
        // 清理控制字符，避免 DOM 解析提前终止
        $html = $this->stripControlChars($html);
        $xpath = $this->buildXpath($html);
        $userMap = $this->extractUserMap($html);

        $sourceThreadId = $this->extractThreadId($html);
        [$page, $pageTotal] = $this->extractPageMeta($html);

        $rows = $xpath->query("//tr[contains(@class,'postrow')]");
        if ($rows === false || $rows->length === 0) {
            throw new RuntimeException('Lite thread HTML missing posts');
        }

        $resultPosts = [];
        $rowIndex = 0;
        foreach ($rows as $row) {
            $rowIndex++;

            $authorNode = $this->firstNode($xpath, ".//a[contains(@href,'uid=')]", $row);
            $authorId = $authorNode ? $this->extractUidFromHref($authorNode->getAttribute('href')) : null;
            $authorName = $authorNode ? $this->cleanText($authorNode->textContent) : '';
            if ($authorName === '' && $authorId !== null && isset($userMap[$authorId])) {
                $authorName = $userMap[$authorId];
            }
            if ($authorName === '' && $authorId !== null) {
                $authorName = 'UID:'.$authorId;
            }
            if ($authorName === '') {
                $authorName = 'unknown';
            }

            $floor = $this->extractFloorFromRow($xpath, $row);
            if ($floor === null) {
                $floor = $rowIndex;
            }

            $sourcePostId = $this->extractPidFromRow($row);

            $dateText = $this->extractPostDateText($xpath, $row);
            $createdAt = $this->safeParseDate($dateText);

            $contentNode = $this->firstNode($xpath, ".//*[@id and starts-with(@id,'postcontent')]", $row)
                ?? $this->firstNode($xpath, ".//*[contains(@class,'postcontent')]", $row);
            $contentHtml = $contentNode ? $this->innerHtml($contentNode) : '';

            [$isDeleted, $isFolded] = $this->detectPostFlags($contentNode);

            $resultPosts[] = [
                'source_post_id' => $sourcePostId,
                'floor_number' => $floor,
                'author_name' => $authorName,
                'author_source_user_id' => $authorId,
                'post_created_at' => $createdAt,
                // HTML 模式暂存原始内容片段（后续由 UBB/HTML 处理任务覆盖）
                'content_raw' => $contentHtml,
                'content_format' => 'html',
                'is_deleted_by_source' => $isDeleted,
                'is_folded_by_source' => $isFolded,
                'source_edited_at' => null,
            ];
        }

        return [
            'source_thread_id' => $sourceThreadId,
            'page' => $page,
            'page_total' => $pageTotal,
            'posts' => $resultPosts,
        ];
    }

    private function parseEditedAt(array $post): ?CarbonImmutable
    {
        if (!array_key_exists('alterinfo', $post) || !is_string($post['alterinfo'])) {
            return $this->dateValue($post, ['edited_at', 'source_edited_at'], true);
        }

        if (preg_match('/\\[E(\\d{10})\\s/', $post['alterinfo'], $matches) !== 1) {
            return $this->dateValue($post, ['edited_at', 'source_edited_at'], true);
        }

        return CarbonImmutable::createFromTimestamp((int) $matches[1], 'Asia/Shanghai');
    }

    private function stringValue(array $data, array $keys, ?string $default = ''): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                return (string) $data[$key];
            }
        }

        return $default ?? '';
    }

    private function intValue(array $data, array $keys, ?int $default = 0): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return (int) $data[$key];
            }
        }

        return $default;
    }

    private function boolValue(array $data, array $keys, bool $default): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $default;
    }

    private function dateValue(array $data, array $keys, bool $nullable = false): ?CarbonImmutable
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            if ($value === null || $value === '') {
                return $nullable ? null : CarbonImmutable::now('Asia/Shanghai');
            }

            if (is_numeric($value)) {
                return CarbonImmutable::createFromTimestamp((int) $value, 'Asia/Shanghai');
            }

            return CarbonImmutable::parse((string) $value, 'Asia/Shanghai');
        }

        return $nullable ? null : CarbonImmutable::now('Asia/Shanghai');
    }

    private function looksLikeHtml(string $raw): bool
    {
        $trimmed = ltrim($raw);
        if ($trimmed === '') {
            return false;
        }

        return str_starts_with($trimmed, '<') || stripos($raw, '<html') !== false;
    }

    private function normalizeHtmlEncoding(string $raw): string
    {
        $normalized = $raw;

        if (
            stripos($normalized, 'charset=gbk') === false
            && stripos($normalized, 'charset=gb2312') === false
            && stripos($normalized, 'charset=gb18030') === false
        ) {
            return $normalized;
        }

        foreach (['GB18030', 'GBK', 'GB2312'] as $encoding) {
            $converted = @mb_convert_encoding($normalized, 'UTF-8', $encoding);
            if ($converted !== false) {
                $normalized = $converted;
                break;
            }
        }

        // 转码后将 charset 标记改为 UTF-8，避免 DOM 解析再次按 GBK 转码
        $normalized = preg_replace(
            '/charset\\s*=\\s*(gbk|gb2312|gb18030)/i',
            'charset=UTF-8',
            $normalized
        ) ?? $normalized;

        return $normalized;
    }

    private function buildXpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    private function extractThreadId(string $html): int
    {
        if (preg_match('/read\\.php\\?tid=(\\d+)/', $html, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/\\btid=(\\d+)/', $html, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function extractPageMeta(string $html): array
    {
        $page = 1;
        $pageTotal = 1;

        if (preg_match('/var\\s+__PAGE\\s*=\\s*\\{([^}]+)\\}/', $html, $matches) === 1) {
            $block = $matches[1];
            if (preg_match('/\\b1\\s*:\\s*(\\d+)/', $block, $match) === 1) {
                $pageTotal = (int) $match[1];
            }
            if (preg_match('/\\b2\\s*:\\s*(\\d+)/', $block, $match) === 1) {
                $page = (int) $match[1];
            }
        }

        if ($pageTotal <= 1 && preg_match_all('/[?&]page=(\\d+)/', $html, $matches)) {
            $pages = array_map('intval', $matches[1]);
            if ($pages !== []) {
                $pageTotal = max($pageTotal, max($pages));
            }
        }

        return [$page, max(1, $pageTotal)];
    }

    /**
     * @return array<int, string>
     */
    private function extractUserMap(string $html): array
    {
        if (preg_match('/commonui\\.userInfo\\.setAll\\((\\{.*\\})\\);/s', $html, $matches) !== 1) {
            return [];
        }

        $json = $this->normalizeJsLikeJson($matches[1]);
        $json = $this->stripControlChars($json);
        $decoded = json_decode($json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $key => $user) {
            if (!is_array($user)) {
                continue;
            }

            $uid = null;
            if (is_string($key) && preg_match('/UID:(\\d+)/', $key, $match) === 1) {
                $uid = (int) $match[1];
            } elseif (is_numeric($key)) {
                $uid = (int) $key;
            } elseif (isset($user['uid']) && is_numeric($user['uid'])) {
                $uid = (int) $user['uid'];
            }

            if ($uid === null) {
                continue;
            }

            $name = '';
            foreach (['nickname', 'username', 'name'] as $field) {
                if (!empty($user[$field])) {
                    $name = (string) $user[$field];
                    break;
                }
            }

            if ($name !== '') {
                $result[$uid] = $name;
            }
        }

        return $result;
    }

    private function normalizeJsLikeJson(string $raw): string
    {
        $normalized = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', function (array $matches): string {
            return '\\u00'.strtolower($matches[1]);
        }, $raw);

        if ($normalized === null) {
            $normalized = $raw;
        }

        return str_replace("\\'", "'", $normalized);
    }

    private function stripControlChars(string $raw): string
    {
        $cleaned = preg_replace('/[\\x00-\\x1F]/', '', $raw);

        return $cleaned === null ? $raw : $cleaned;
    }

    private function extractUidFromHref(string $href): ?int
    {
        if (preg_match('/\\buid=(\\d+)/', $href, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function extractPostDateText(DOMXPath $xpath, DOMNode $row): ?string
    {
        $node = $this->firstNode($xpath, ".//*[@id and starts-with(@id,'postdate')]", $row)
            ?? $this->firstNode($xpath, ".//span[contains(@class,'postdate')]", $row);
        if ($node === null) {
            return null;
        }

        return $this->cleanText($node->textContent);
    }

    private function extractFloorFromRow(DOMXPath $xpath, DOMNode $row): ?int
    {
        $anchors = $xpath->query(".//a[@name or @id]", $row);
        if ($anchors !== false) {
            foreach ($anchors as $anchor) {
                foreach (['name', 'id'] as $attr) {
                    $value = $anchor->getAttribute($attr);
                    if ($value !== '' && preg_match('/\\bl(\\d+)\\b/', $value, $matches) === 1) {
                        return ((int) $matches[1]) + 1;
                    }
                }
            }
        }

        $floorNode = $this->firstNode($xpath, ".//*[contains(@class,'postnum') or contains(@class,'floor')]", $row);
        if ($floorNode !== null && preg_match('/\\d+/', $floorNode->textContent, $matches) === 1) {
            return (int) $matches[0];
        }

        return null;
    }

    private function extractPidFromRow(DOMNode $row): int
    {
        $html = $row->C14N();
        if ($html === false) {
            return 0;
        }

        if (preg_match('/pid(\\d+)Anchor/', $html, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return trim($html);
    }

    /**
     * @return array{0:bool, 1:bool}
     */
    private function detectPostFlags(?DOMNode $contentNode): array
    {
        if ($contentNode === null) {
            return [false, false];
        }

        $text = $this->cleanText($contentNode->textContent);

        $isDeleted = str_contains($text, '帖子被删除') || str_contains($text, '该帖被删除');
        $isFolded = str_contains($text, '帖子被折叠') || str_contains($text, '被折叠');

        return [$isDeleted, $isFolded];
    }

    private function cleanText(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\\s+/', ' ', $decoded) ?? '');
    }

    private function safeParseDate(?string $value): CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return CarbonImmutable::now('Asia/Shanghai');
        }

        try {
            if (is_numeric($value)) {
                return CarbonImmutable::createFromTimestamp((int) $value, 'Asia/Shanghai');
            }

            return CarbonImmutable::parse($value, 'Asia/Shanghai');
        } catch (\Throwable) {
            return CarbonImmutable::now('Asia/Shanghai');
        }
    }

    private function firstNode(DOMXPath $xpath, string $query, DOMNode $context): ?DOMNode
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        return $nodes->item(0);
    }
}
