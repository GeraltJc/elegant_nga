<?php

namespace App\Services\Nga;

class UbbToHtmlConverter
{
    private const SIMPLE_TAGS = [
        'b' => 'strong',
        'i' => 'em',
        'u' => 'u',
        's' => 's',
        'del' => 'del',
    ];

    public function __construct(private readonly SafeUrlPolicy $urlPolicy)
    {
    }

    public function convert(string $input): string
    {
        $input = $this->normalizeLineBreaks($input);

        return $this->convertSegment($input);
    }

    private function convertSegment(string $input): string
    {
        $output = '';
        $offset = 0;
        $length = strlen($input);
        $stack = [];

        while ($offset < $length) {
            $nextTagPos = strpos($input, '[', $offset);
            if ($nextTagPos === false) {
                $output .= $this->escapeTextWithBreaks(substr($input, $offset));
                break;
            }

            $output .= $this->escapeTextWithBreaks(substr($input, $offset, $nextTagPos - $offset));

            if (!preg_match('/\\G\\[(\\/?)([a-z]+)(?:=([^\\]]+))?\\]/i', $input, $match, 0, $nextTagPos)) {
                $output .= $this->escapeTextWithBreaks('[');
                $offset = $nextTagPos + 1;
                continue;
            }

            $fullTag = $match[0];
            $isClosing = $match[1] === '/';
            $tagName = strtolower($match[2]);
            $tagAttr = $match[3] ?? null;
            $tagEnd = $nextTagPos + strlen($fullTag);

            if (!$isClosing && in_array($tagName, ['code', 'url', 'img', 'list', 'quote'], true)) {
                $contentInfo = $this->consumeTagContent($input, $tagEnd, $tagName);
                if ($contentInfo === null) {
                    $output .= $this->escapeTextWithBreaks($fullTag);
                    $offset = $tagEnd;
                    continue;
                }

                [$inner, $afterPos] = $contentInfo;
                $rawSegment = substr($input, $nextTagPos, $afterPos - $nextTagPos);

                if ($tagName === 'code') {
                    $output .= '<pre><code>'.$this->escapeText($inner).'</code></pre>';
                } elseif ($tagName === 'quote') {
                    $output .= '<blockquote>'.$this->convertSegment($inner).'</blockquote>';
                } elseif ($tagName === 'list') {
                    $output .= $this->renderList($inner, $tagAttr);
                } elseif ($tagName === 'url') {
                    $output .= $this->renderUrl($inner, $tagAttr, $rawSegment);
                } elseif ($tagName === 'img') {
                    $output .= $this->renderImg($inner, $rawSegment);
                }

                $offset = $afterPos;
                continue;
            }

            if (array_key_exists($tagName, self::SIMPLE_TAGS)) {
                $htmlTag = self::SIMPLE_TAGS[$tagName];
                if (!$isClosing) {
                    $stack[] = $htmlTag;
                    $output .= "<{$htmlTag}>";
                } else {
                    if ($stack !== [] && end($stack) === $htmlTag) {
                        array_pop($stack);
                        $output .= "</{$htmlTag}>";
                    } else {
                        $output .= $this->escapeTextWithBreaks($fullTag);
                    }
                }
            } else {
                $output .= $this->escapeTextWithBreaks($fullTag);
            }

            $offset = $tagEnd;
        }

        while ($stack !== []) {
            $htmlTag = array_pop($stack);
            $output .= "</{$htmlTag}>";
        }

        return $output;
    }

    private function renderUrl(string $inner, ?string $tagAttr, string $rawSegment): string
    {
        $hrefCandidate = $tagAttr ?? $inner;
        $href = $this->urlPolicy->normalize($hrefCandidate);
        if ($href === null) {
            return $this->escapeTextWithBreaks($rawSegment);
        }

        $text = $this->escapeTextWithBreaks($inner);
        $safeHref = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<a href="'.$safeHref.'" rel="nofollow noopener noreferrer" target="_blank">'.$text.'</a>';
    }

    private function renderImg(string $inner, string $rawSegment): string
    {
        $src = $this->urlPolicy->normalize($inner);
        if ($src === null) {
            return $this->escapeTextWithBreaks($rawSegment);
        }

        $safeSrc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<img src="'.$safeSrc.'" alt="" loading="lazy" referrerpolicy="no-referrer">';
    }

    private function renderList(string $inner, ?string $tagAttr): string
    {
        $listTag = $this->isOrderedList($tagAttr) ? 'ol' : 'ul';
        $parts = preg_split('/\\[\\*\\]/i', $inner);
        if ($parts === false || $parts === []) {
            return $this->escapeTextWithBreaks($inner);
        }

        $items = [];
        $first = array_shift($parts);
        if ($first !== null && trim($first) !== '') {
            $items[] = $first;
        }
        foreach ($parts as $part) {
            if (trim($part) === '') {
                continue;
            }
            $items[] = $part;
        }

        if ($items === []) {
            $items[] = $inner;
        }

        $htmlItems = '';
        foreach ($items as $item) {
            $htmlItems .= '<li>'.$this->convertSegment($item).'</li>';
        }

        return "<{$listTag}>{$htmlItems}</{$listTag}>";
    }

    private function isOrderedList(?string $tagAttr): bool
    {
        if ($tagAttr === null) {
            return false;
        }

        return trim(strtolower($tagAttr)) === '1';
    }

    private function consumeTagContent(string $input, int $start, string $tagName): ?array
    {
        $closingPos = $this->findClosingTag($input, $start, $tagName);
        if ($closingPos === null) {
            return null;
        }

        $closeLength = strlen('[/'.$tagName.']');
        $inner = substr($input, $start, $closingPos - $start);

        return [$inner, $closingPos + $closeLength];
    }

    private function findClosingTag(string $input, int $offset, string $tagName): ?int
    {
        $pattern = '/\\[(\\/?)'.preg_quote($tagName, '/').'(?:=[^\\]]+)?\\]/i';
        $depth = 1;

        while (preg_match($pattern, $input, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $isClosing = $match[1][0] === '/';
            $pos = $match[0][1];
            $offset = $pos + strlen($match[0][0]);

            if ($isClosing) {
                $depth--;
                if ($depth === 0) {
                    return $pos;
                }
            } else {
                $depth++;
            }
        }

        return null;
    }

    private function normalizeLineBreaks(string $input): string
    {
        return str_replace(["\r\n", "\r"], "\n", $input);
    }

    private function escapeText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeTextWithBreaks(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return str_replace("\n", '<br>', $this->escapeText($text));
    }
}
