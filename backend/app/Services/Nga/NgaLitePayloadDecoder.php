<?php

namespace App\Services\Nga;

use RuntimeException;

class NgaLitePayloadDecoder
{
    public function decode(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new RuntimeException('Empty lite=js payload');
        }

        // 从 HTML 中截取包含 window.script_muti_get_var_store 的片段
        $trimmed = $this->extractScriptStoreFromHtml($trimmed);
        // 去掉脚本前缀并清理控制字符，保证 JSON 可解析
        $trimmed = $this->stripJsAssignmentPrefix($trimmed);
        $trimmed = $this->stripControlChars($trimmed);
        $decoded = json_decode($trimmed, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_array($decoded)) {
            return $decoded;
        }

        $normalizedEncoding = $this->normalizeEncoding($trimmed);
        if ($normalizedEncoding !== $trimmed) {
            $decoded = json_decode($normalizedEncoding, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $normalizedInline = $this->normalizeJsLikeJson($trimmed);
        if ($normalizedInline !== $trimmed) {
            $decoded = json_decode($normalizedInline, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $candidate = $this->extractJsonCandidate($trimmed);
        if ($candidate !== null) {
            $normalized = $this->normalizeJsLikeJson($candidate);
            $decoded = json_decode($normalized, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('Unable to decode lite=js payload');
    }

    private function extractJsonCandidate(string $raw): ?string
    {
        if (preg_match('/=\s*({.*})\s*;?\s*$/s', $raw, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/({.*})/s', $raw, $matches) === 1) {
            return $matches[1];
        }

        return null;
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

    private function normalizeEncoding(string $raw): string
    {
        if (
            stripos($raw, 'encode":"gbk"') === false
            && stripos($raw, 'encode":"GBK"') === false
            && stripos($raw, 'charset=gbk') === false
            && stripos($raw, 'charset=GBK') === false
        ) {
            return $raw;
        }

        foreach (['GB18030', 'GBK', 'GB2312'] as $encoding) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', $encoding);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $raw;
    }

    private function stripJsAssignmentPrefix(string $raw): string
    {
        $prefix = 'window.script_muti_get_var_store=';
        if (str_starts_with($raw, $prefix)) {
            $raw = substr($raw, strlen($prefix));
        }

        return rtrim($raw, ";\n\r\t ");
    }

    private function extractScriptStoreFromHtml(string $raw): string
    {
        $prefix = 'window.script_muti_get_var_store=';
        $pos = strpos($raw, $prefix);
        if ($pos === false) {
            return $raw;
        }

        $slice = substr($raw, $pos);
        $end = stripos($slice, '</script>');
        if ($end !== false) {
            $slice = substr($slice, 0, $end);
        }

        return $slice;
    }

    private function stripControlChars(string $raw): string
    {
        $cleaned = preg_replace('/[\\x00-\\x1F]/', '', $raw);

        return $cleaned === null ? $raw : $cleaned;
    }
}
