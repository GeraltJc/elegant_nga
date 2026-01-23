<?php

namespace App\Services\Nga;

class NgaPostContentProcessor
{
    public function __construct(
        private readonly UbbToHtmlConverter $ubbConverter,
        private readonly HtmlSanitizer $sanitizer
    ) {
    }

    public static function makeDefault(): self
    {
        $policy = new SafeUrlPolicy();

        return new self(
            new UbbToHtmlConverter($policy),
            new HtmlSanitizer($policy)
        );
    }

    public function toSafeHtml(string $raw, ?string $format): string
    {
        $format = strtolower(trim((string) $format));
        $html = $format === 'html' ? $raw : $this->ubbConverter->convert($raw);

        $sanitized = $this->sanitizer->sanitize($html);

        return $this->trimEdgeLineBreaks($sanitized);
    }

    private function trimEdgeLineBreaks(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // 关键规则：移除仅位于首尾的 <br>，避免引入原文没有的空行
        $trimmed = preg_replace('/^(?:\\s*<br\\s*\\/?>\\s*)+/i', '', $html);
        $trimmed = preg_replace('/(?:\\s*<br\\s*\\/?>\\s*)+$/i', '', $trimmed ?? '');

        return $trimmed ?? '';
    }
}
