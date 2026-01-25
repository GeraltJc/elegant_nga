<?php

namespace App\Services\Nga;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class HttpNgaLiteClient implements NgaLiteClient
{
    private const BASE_URL = 'https://nga.178.com';
    private const DEFAULT_CONNECT_TIMEOUT = 5.0;
    private const DEFAULT_TIMEOUT = 20.0;
    private const DEFAULT_RETRY_TIMES = 3;
    private const DEFAULT_RETRY_DELAY_MS = 200;

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
            'order_by' => 'postdatedesc',
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
            'order_by' => 'postdatedesc',
        ], false);

        if ($this->isGuestBlocked($response)) {
            // 被拦截时，刷新一次访客请求再尝试获取 cookie
            $response = $this->request('thread.php', [
                'fid' => $this->forumId,
                'order_by' => 'postdatedesc',
            ], false);
        }

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

        $headers = $this->defaultHeaders();
        $http = Http::withHeaders($headers)
            ->connectTimeout($this->resolveConnectTimeout())
            ->timeout($this->resolveTimeout())
            ->retry(
                $this->resolveRetryTimes(),
                $this->resolveRetryDelayMs(),
                function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        $status = $exception->response?->status();
                        if ($status === null) {
                            return true;
                        }

                        return $status === 408 || $status === 429 || $status >= 500;
                    }

                    return false;
                },
                // 关键规则：不在失败响应时抛异常，确保 403 场景仍能解析访客 cookie
                false
            );

        if ($this->shouldForceIpv4()) {
            $http = $http->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ]);
        }
        if ($withCookies && $this->guestJs !== null && $this->lastVisit !== null) {
            // 访客身份通过 cookie 维持
            $http = $http->withCookies([
                'guestJs' => $this->guestJs,
                'lastvisit' => $this->lastVisit,
            ], 'nga.178.com');
        }

        $url = $this->buildUrl($path);
        // 记录完整 curl，便于回放定位拦截/访问异常
        $this->logCurlCommand($url, $query, $headers, $withCookies);
        $start = microtime(true);

        try {
            $response = $http->get($url, $query);
        } catch (\Throwable $exception) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            Log::error('NGA HTTP request failed', [
                'method' => 'GET',
                'url' => $url,
                'query' => $query,
                'with_cookies' => $withCookies,
                'headers' => $headers,
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        Log::info('NGA HTTP request finished', [
            'method' => 'GET',
            'url' => $url,
            'query' => $query,
            'with_cookies' => $withCookies,
            'headers' => $headers,
            'status' => $response->status(),
            'duration_ms' => $durationMs,
            'body_bytes' => strlen((string) $response->body()),
        ]);

        return $response;
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

    private function resolveConnectTimeout(): float
    {
        return $this->normalizePositiveFloat(env('NGA_HTTP_CONNECT_TIMEOUT', self::DEFAULT_CONNECT_TIMEOUT), self::DEFAULT_CONNECT_TIMEOUT);
    }

    private function resolveTimeout(): float
    {
        return $this->normalizePositiveFloat(env('NGA_HTTP_TIMEOUT', self::DEFAULT_TIMEOUT), self::DEFAULT_TIMEOUT);
    }

    private function resolveRetryTimes(): int
    {
        return $this->normalizePositiveInt(env('NGA_HTTP_RETRY_TIMES', self::DEFAULT_RETRY_TIMES), self::DEFAULT_RETRY_TIMES);
    }

    private function resolveRetryDelayMs(): int
    {
        return $this->normalizeNonNegativeInt(env('NGA_HTTP_RETRY_DELAY_MS', self::DEFAULT_RETRY_DELAY_MS), self::DEFAULT_RETRY_DELAY_MS);
    }

    private function shouldForceIpv4(): bool
    {
        $value = env('NGA_FORCE_IPV4');
        if ($value === null) {
            return false;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $bool ?? false;
    }

    private function normalizePositiveFloat(mixed $value, float $fallback): float
    {
        if (is_numeric($value)) {
            $number = (float) $value;
            if ($number > 0) {
                return $number;
            }
        }

        return $fallback;
    }

    private function normalizePositiveInt(mixed $value, int $fallback): int
    {
        if (is_numeric($value)) {
            $number = (int) $value;
            if ($number > 0) {
                return $number;
            }
        }

        return $fallback;
    }

    private function normalizeNonNegativeInt(mixed $value, int $fallback): int
    {
        if (is_numeric($value)) {
            $number = (int) $value;
            if ($number >= 0) {
                return $number;
            }
        }

        return $fallback;
    }

    private function logCurlCommand(string $url, array $query, array $headers, bool $withCookies): void
    {
        $command = $this->buildCurlCommand($url, $query, $headers, $withCookies);
        $logPath = $this->resolveCurlLogPath();
        if ($logPath !== null) {
            // 辅助排查：按请求顺序追加记录
            @file_put_contents($logPath, $command.PHP_EOL, FILE_APPEND);
        }
        Log::info('NGA curl command', ['command' => $command]);
    }

    private function buildCurlCommand(string $url, array $query, array $headers, bool $withCookies): string
    {
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $fullUrl = $queryString === '' ? $url : $url.'?'.$queryString;

        $parts = ['curl '.escapeshellarg($fullUrl)];

        if ($withCookies && $this->guestJs !== null && $this->lastVisit !== null) {
            $cookie = 'lastvisit='.$this->lastVisit.'; guestJs='.$this->guestJs;
            $parts[] = '-b '.escapeshellarg($cookie);
        }

        foreach ($headers as $name => $value) {
            $parts[] = '-H '.escapeshellarg($name.': '.$value);
        }

        return implode(" \\\n  ", $parts);
    }

    private function resolveCurlLogPath(): ?string
    {
        $path = (string) env('NGA_CURL_LOG_PATH', storage_path('logs/nga-curl.log'));
        if ($path === '') {
            // 空字符串表示禁用 curl 日志落盘
            return null;
        }

        return $path;
    }
}
