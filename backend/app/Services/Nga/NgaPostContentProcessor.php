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

        return $this->sanitizer->sanitize($html);
    }
}
