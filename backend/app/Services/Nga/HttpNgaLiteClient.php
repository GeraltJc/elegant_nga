<?php

namespace App\Services\Nga;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpNgaLiteClient implements NgaLiteClient
{
    private const BASE_URL = 'https://nga.178.com';

    private ?string $guestJs = null;
    private ?string $lastVisit = null;

    public function __construct(private readonly int $forumId = 7)
    {
    }

    public function fetchList(int $fid, int $page = 1): string
    {
        return $this->fetchWithGuest('thread.php', [
            'fid' => $fid,
            'page' => $page,
        ]);
    }

    public function fetchThread(int $tid, int $page = 1): string
    {
        return $this->fetchWithGuest('read.php', [
            'tid' => $tid,
            'page' => $page,
        ]);
    }

    private function fetchWithGuest(string $path, array $query): string
    {
        $this->ensureGuestCookies();

        $response = $this->request($path, $query, true);
        if ($this->isGuestBlocked($response)) {
            // 遇到访客拦截时刷新 cookie 后重试一次
            $this->refreshGuestCookies();
            $response = $this->request($path, $query, true);
        }

        if (!$response->successful()) {
            throw new RuntimeException("NGA request failed: {$response->status()}");
        }

        return $response->body();
    }

    private function ensureGuestCookies(): void
    {
        if ($this->guestJs !== null && $this->lastVisit !== null) {
            return;
        }

        $this->refreshGuestCookies();
    }

    private function refreshGuestCookies(): void
    {
        // 首次访客访问用于获取 guestJs 与 lastvisit
        $response = $this->request('thread.php', [
            'fid' => $this->forumId,
        ], false);

        $guestJs = $this->extractGuestJs($response->body());
        $lastVisit = $this->extractCookieValue($response, 'lastvisit');

        if ($guestJs === null || $lastVisit === null) {
            throw new RuntimeException('Unable to acquire guest cookies from NGA response.');
        }

        $this->guestJs = $guestJs;
        $this->lastVisit = $lastVisit;
    }

    private function request(string $path, array $query, bool $withCookies): Response
    {
        $query['rand'] = $this->randomRand();

        $http = Http::withHeaders($this->defaultHeaders());
        if ($withCookies && $this->guestJs !== null && $this->lastVisit !== null) {
            // 访客身份通过 cookie 维持
            $http = $http->withCookies([
                'guestJs' => $this->guestJs,
                'lastvisit' => $this->lastVisit,
            ], 'nga.178.com');
        }

        return $http->get($this->buildUrl($path), $query);
    }

    private function defaultHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => $this->refererUrl(),
        ];
    }

    private function buildUrl(string $path): string
    {
        return rtrim(self::BASE_URL, '/').'/'.$path;
    }

    private function refererUrl(): string
    {
        return self::BASE_URL.'/thread.php?fid='.$this->forumId;
    }

    private function randomRand(): int
    {
        // rand 要求 100~999
        return random_int(100, 999);
    }

    private function isGuestBlocked(Response $response): bool
    {
        if ($response->status() === 403) {
            return true;
        }

        $body = $response->body();

        return str_contains($body, '访客不能直接访问') || str_contains($body, 'ERROR:15');
    }

    private function extractGuestJs(string $body): ?string
    {
        // 从响应体里提取 guestJs
        if (preg_match('/guestJs=([0-9]+_[0-9a-z]+)/i', $body, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractCookieValue(Response $response, string $name): ?string
    {
        $setCookie = $response->header('Set-Cookie');
        $cookies = [];
        if (is_array($setCookie)) {
            $cookies = $setCookie;
        } elseif (is_string($setCookie)) {
            $cookies = [$setCookie];
        }

        foreach ($cookies as $cookie) {
            if (preg_match('/\\b'.preg_quote($name, '/').'=([^;]+)/', $cookie, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
