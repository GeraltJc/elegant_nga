<?php

namespace App\Services\Nga;

use DOMDocument;
use DOMNode;
use HTMLPurifier;
use HTMLPurifier_Config;

class HtmlSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct(private readonly SafeUrlPolicy $urlPolicy, ?HTMLPurifier $purifier = null)
    {
        $this->purifier = $purifier ?? $this->buildPurifier();
    }

    public function sanitize(string $html): string
    {
        $purified = $this->purifier->purify($html);

        return $this->applyPolicies($purified);
    }

    private function buildPurifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set(
            'HTML.Allowed',
            'a[href|rel|target],br,blockquote,pre,code,strong,em,u,s,del,ul,ol,li,img[src|alt|loading|referrerpolicy]'
        );
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('AutoFormat.AutoParagraph', false);

        return new HTMLPurifier($config);
    }

    private function applyPolicies(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->enforceLinkPolicy($document);
        $this->enforceImagePolicy($document);

        $container = $document->getElementsByTagName('div')->item(0);
        if ($container === null) {
            return '';
        }

        return $this->innerHtmlFromNode($container);
    }

    private function enforceLinkPolicy(DOMDocument $document): void
    {
        $links = iterator_to_array($document->getElementsByTagName('a'));
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $safeHref = $this->urlPolicy->normalize($href);
            if ($safeHref === null) {
                $link->removeAttribute('href');
                $link->removeAttribute('rel');
                $link->removeAttribute('target');
                continue;
            }

            $link->setAttribute('href', $safeHref);
            $link->setAttribute('rel', 'nofollow noopener noreferrer');
            $link->setAttribute('target', '_blank');
        }
    }

    private function enforceImagePolicy(DOMDocument $document): void
    {
        $images = iterator_to_array($document->getElementsByTagName('img'));
        foreach ($images as $image) {
            $src = $image->getAttribute('src');
            $safeSrc = $this->urlPolicy->normalize($src);
            if ($safeSrc === null) {
                $image->parentNode?->removeChild($image);
                continue;
            }

            $image->setAttribute('src', $safeSrc);
            if (!$image->hasAttribute('alt')) {
                $image->setAttribute('alt', '');
            }
            $image->setAttribute('loading', 'lazy');
            $image->setAttribute('referrerpolicy', 'no-referrer');
        }
    }

    private function innerHtmlFromNode(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_PI_NODE) {
                continue;
            }
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }
}
