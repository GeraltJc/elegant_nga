<?php

namespace App\Services\Nga;

class SafeUrlPolicy
{
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = trim($decoded);
        if ($decoded === '') {
            return null;
        }

        if (preg_match('/[\\x00-\\x1F\\x7F\\s]/', $decoded)) {
            return null;
        }

        $parts = parse_url($decoded);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (empty($parts['host'])) {
            return null;
        }

        return $decoded;
    }
}
