<?php

namespace App\Services\Nga;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMNode;
use DOMXPath;
use RuntimeException;

class NgaLiteListParser
{
    public function __construct(private readonly NgaLitePayloadDecoder $decoder)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $raw): array
    {
        if ($this->looksLikeHtml($raw)) {
            return $this->parseHtml($raw);
        }

        return $this->parseJson($raw);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseJson(string $raw): array
    {
        $payload = $this->decoder->decode($raw);
        $threads = $payload['threads']
            ?? $payload['data']['threads']
            ?? $payload['t']
            ?? $payload['data']['t']
            ?? $payload['data']['__T']
            ?? $payload['__T']
            ?? null;

        if (!is_array($threads)) {
            throw new RuntimeException('Lite list payload missing threads');
        }

        $threads = array_values($threads);
        $results = [];
        foreach ($threads as $thread) {
            if (!is_array($thread)) {
                continue;
            }

            $title = $this->stringValue($thread, ['title', 'subject']);
            $titlePrefix = $this->extractTitlePrefix($title);

            // 业务含义：列表缺失回复数时保持 null，避免误写为 0。
            $replyCountDisplay = $this->intValue($thread, ['reply_count', 'replies', 'reply_count_display'], null);

            $results[] = [
                'source_thread_id' => $this->intValue($thread, ['tid', 'thread_id', 'id']),
                'title' => $title,
                'title_prefix_text' => $titlePrefix,
                'author_name' => $this->stringValue($thread, ['author', 'author_name', 'poster']),
                'author_source_user_id' => $this->intValue($thread, ['author_id', 'author_uid', 'author_source_user_id', 'authorid'], null),
                'thread_created_at' => $this->dateValue($thread, ['post_time', 'thread_created_at', 'created_at', 'post_at', 'postdate']),
                'last_reply_at' => $this->dateValue($thread, ['last_reply', 'last_reply_at', 'reply_at', 'lastpost'], true),
                'reply_count_display' => $replyCountDisplay,
                'view_count_display' => $this->intValue($thread, ['view_count', 'views', 'view_count_display', 'hits'], null),
                'is_pinned' => $this->boolValue($thread, ['is_pinned', 'pinned', 'top'], false),
                'is_digest' => $this->boolValue($thread, ['is_digest', 'digest'], false),
            ];
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseHtml(string $raw): array
    {
        // HTML 页面需要先转 UTF-8 再解析 DOM
        $html = $this->normalizeHtmlEncoding($raw);
        // 清理控制字符，避免 DOM 解析提前终止
        $html = $this->stripControlChars($html);
        $topicMeta = $this->extractTopicMeta($html);
        $xpath = $this->buildXpath($html);

        $rows = $xpath->query("//tr[contains(@class,'topicrow')]");
        if ($rows === false || $rows->length === 0) {
            throw new RuntimeException('Lite list HTML missing topic rows');
        }

        $results = [];
        foreach ($rows as $row) {
            $topicLink = $this->selectBestTopicLink($xpath, $row);
            if ($topicLink === null) {
                continue;
            }

            $tid = $this->extractTidFromHref($topicLink->getAttribute('href'));
            if ($tid <= 0) {
                continue;
            }

            $title = $this->extractTopicTitle($topicLink);

            $authorNode = $this->firstNode($xpath, ".//a[contains(@href,'uid=')]", $row);
            $authorId = $authorNode ? $this->extractUidFromHref($authorNode->getAttribute('href')) : null;
            $authorName = $authorNode ? $this->cleanText($authorNode->textContent) : '';
            if ($authorName === '' && $authorId !== null) {
                $authorName = 'UID:'.$authorId;
            }
            if ($authorName === '') {
                $authorName = 'unknown';
            }

            $meta = $topicMeta[$tid] ?? [];
            $createdAt = $this->dateFromTimestamp($meta['postdate'] ?? null, true);
            $lastReplyAt = $this->dateFromTimestamp($meta['lastpost'] ?? null, true);
            $replyCount = isset($meta['replies']) ? (int) $meta['replies'] : null;

            if ($createdAt === null || $lastReplyAt === null) {
                [$createdText, $lastText] = $this->extractRowDates($xpath, $row);
                if ($createdAt === null && $createdText !== null) {
                    $createdAt = $this->safeParseDate($createdText, false);
                }
                if ($lastReplyAt === null && $lastText !== null) {
                    $lastReplyAt = $this->safeParseDate($lastText, true);
                }
            }

            if ($replyCount === null) {
                // 业务含义：列表无法解析回复数时保持 null，避免误写为 0。
                $replyCount = $this->extractReplyCount($xpath, $row);
            }

            $type = $meta['type'] ?? null;
            $results[] = [
                'source_thread_id' => $tid,
                'title' => $title,
                'title_prefix_text' => $this->extractTitlePrefix($title),
                'author_name' => $authorName,
                'author_source_user_id' => $authorId,
                'thread_created_at' => $createdAt ?? CarbonImmutable::now('Asia/Shanghai'),
                'last_reply_at' => $lastReplyAt,
                'reply_count_display' => $replyCount,
                'view_count_display' => null,
                // HTML 列表页不提供浏览量，置顶/精华用脚本参数与图标做弱判断
                'is_pinned' => $this->isPinnedByType($type) || $this->rowHasMarker($xpath, $row, '置顶'),
                'is_digest' => $this->rowHasMarker($xpath, $row, '精华'),
            ];
        }

        return $results;
    }

    private function extractTitlePrefix(string $title): ?string
    {
        if (preg_match('/^\[(.+?)]/', $title, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function selectBestTopicLink(DOMXPath $xpath, DOMNode $row): ?DOMNode
    {
        $nodes = $xpath->query(".//a[contains(@href,'read.php?tid=')]", $row);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $bestNode = null;
        $bestScore = -1;

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $candidate = $this->extractTopicTitle($node);
            if ($candidate === '') {
                continue;
            }

            // 业务规则：标题通常是非纯数字，优先选出“最长的非数字标题”
            $score = ($this->isNumericText($candidate) ? 0 : 100) + $this->stringLength($candidate);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestNode = $node;
            }
        }

        return $bestNode ?? $nodes->item(0);
    }

    private function extractTopicTitle(\DOMElement $node): string
    {
        $text = $this->cleanText($node->textContent);
        $titleAttr = $this->cleanText($node->getAttribute('title'));

        if ($text !== '' && !$this->isNumericText($text)) {
            return $text;
        }

        if ($titleAttr !== '' && !$this->isNumericText($titleAttr)) {
            return $titleAttr;
        }

        return $text !== '' ? $text : $titleAttr;
    }

    private function isNumericText(string $text): bool
    {
        return preg_match('/^\d+$/', $text) === 1;
    }

    private function stringLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
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

    /**
     * @return array<int, array{postdate:int, lastpost:int, replies:int, type:int|null}>
     */
    private function extractTopicMeta(string $html): array
    {
        $result = [];
        $matchCount = preg_match_all('/commonui\\.topicArg\\.add\\(([^\\)]+)\\)/', $html, $matches);
        if ($matchCount === false || $matchCount < 1) {
            return $result;
        }

        foreach ($matches[1] as $argsString) {
            $args = array_map('trim', explode(',', $argsString));
            $tid = $this->parseIntArg($args[1] ?? null);
            if ($tid === null || $tid <= 0) {
                continue;
            }

            $result[$tid] = [
                'postdate' => $this->parseIntArg($args[2] ?? null) ?? 0,
                'lastpost' => $this->parseIntArg($args[3] ?? null) ?? 0,
                'replies' => $this->parseIntArg($args[4] ?? null) ?? 0,
                'type' => $this->parseIntArg($args[5] ?? null),
            ];
        }

        return $result;
    }

    private function parseIntArg(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/-?\\d+/', $value, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    private function extractTidFromHref(string $href): int
    {
        if (preg_match('/\\btid=(\\d+)/', $href, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private function extractUidFromHref(string $href): ?int
    {
        if (preg_match('/\\buid=(\\d+)/', $href, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function cleanText(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\\s+/', ' ', $decoded) ?? '');
    }

    /**
     * @return array{0:string|null, 1:string|null}
     */
    private function extractRowDates(DOMXPath $xpath, DOMNode $row): array
    {
        $nodes = $xpath->query(".//span[contains(@class,'postdate')]", $row);
        if ($nodes === false || $nodes->length === 0) {
            return [null, null];
        }

        $values = [];
        foreach ($nodes as $node) {
            $values[] = $this->cleanText($node->textContent);
        }

        $created = $values[0] ?? null;
        $last = $values[count($values) - 1] ?? null;

        return [$created, $last];
    }

    private function extractReplyCount(DOMXPath $xpath, DOMNode $row): ?int
    {
        $node = $this->firstNode($xpath, ".//td[contains(@class,'replies')]//a", $row)
            ?? $this->firstNode($xpath, ".//td[contains(@class,'replies')]", $row);
        if ($node === null) {
            return null;
        }

        if (preg_match('/\\d+/', $node->textContent, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    private function dateFromTimestamp(?int $timestamp, bool $nullable): ?CarbonImmutable
    {
        if ($timestamp === null || $timestamp <= 0) {
            return $nullable ? null : CarbonImmutable::now('Asia/Shanghai');
        }

        return CarbonImmutable::createFromTimestamp($timestamp, 'Asia/Shanghai');
    }

    private function safeParseDate(?string $value, bool $nullable): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return $nullable ? null : CarbonImmutable::now('Asia/Shanghai');
        }

        try {
            if (is_numeric($value)) {
                return CarbonImmutable::createFromTimestamp((int) $value, 'Asia/Shanghai');
            }

            return CarbonImmutable::parse($value, 'Asia/Shanghai');
        } catch (\Throwable) {
            return $nullable ? null : CarbonImmutable::now('Asia/Shanghai');
        }
    }

    private function isPinnedByType(?int $type): bool
    {
        if ($type === null) {
            return false;
        }

        // NGA 的 type 位标记未完全公开，这里仅对置顶位做保守判断
        return ($type & 134217728) === 134217728;
    }

    private function rowHasMarker(DOMXPath $xpath, DOMNode $row, string $marker): bool
    {
        $icons = $xpath->query(".//img[@alt or @title]", $row);
        if ($icons !== false) {
            foreach ($icons as $icon) {
                $alt = $icon->getAttribute('alt') ?: $icon->getAttribute('title');
                if ($alt !== '' && str_contains($alt, $marker)) {
                    return true;
                }
            }
        }

        return str_contains($row->textContent, $marker);
    }

    private function stripControlChars(string $raw): string
    {
        $cleaned = preg_replace('/[\\x00-\\x1F]/', '', $raw);

        return $cleaned === null ? $raw : $cleaned;
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
