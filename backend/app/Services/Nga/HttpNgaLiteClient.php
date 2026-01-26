<?php

namespace App\Services\Nga;

use App\Services\Nga\Exceptions\NgaRequestException;
use App\Services\Nga\CrawlErrorSummary;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NGA HTTP 客户端，负责访客抓取、限速与退避重试。
 */
class HttpNgaLiteClient implements NgaLiteClient
{
    /**
     * 规则：NGA 访客模式基础 URL。
     */
    private const BASE_URL = 'https://nga.178.com';

    /**
     * 规则：默认连接超时（秒）。
     */
    private const DEFAULT_CONNECT_TIMEOUT = 5.0;

    /**
     * 规则：默认请求超时（秒）。
     */
    private const DEFAULT_TIMEOUT = 20.0;

    /**
     * 规则：默认重试次数（不含首次请求）。
     */
    private const DEFAULT_RETRY_TIMES = 3;

    /**
     * 规则：默认重试基准延迟（毫秒）。
     */
    private const DEFAULT_RETRY_DELAY_MS = 200;

    /**
     * 规则：指数退避最大延迟（毫秒），避免退避时间无限增长。
     */
    private const DEFAULT_RETRY_MAX_DELAY_MS = 5000;

    private ?string $guestJs = null;
    private ?string $lastVisit = null;
    /**
     * @var callable|null 请求尝试观察器，用于统计请求次数。
     */
    private $requestAttemptObserver = null;
    private ?float $requestRateLimitPerSec = null;
    private ?float $lastRequestAtMs = null;

    /**
     * @param int $forumId 默认 fid
     */
    public function __construct(private readonly int $forumId = 7)
    {
    }

    /**
     * 抓取版块列表页 HTML。
     *
     * @param int $fid 版块 fid
     * @param int $page 列表页码
     * @return string HTML 内容
     * @throws NgaRequestException 访客请求失败
     * 无副作用。
     */
    public function fetchList(int $fid, int $page = 1): string
    {
        return $this->fetchWithGuest('thread.php', [
            'fid' => $fid,
            'page' => $page,
            'order_by' => 'postdatedesc',
        ]);
    }

    /**
     * 抓取主题详情页 HTML。
     *
     * @param int $tid 主题 tid
     * @param int $page 主题页码
     * @return string HTML 内容
     * @throws NgaRequestException 访客请求失败
     * 无副作用。
     */
    public function fetchThread(int $tid, int $page = 1): string
    {
        return $this->fetchWithGuest('read.php', [
            'tid' => $tid,
            'page' => $page,
        ]);
    }

    /**
     * 设置请求尝试观察器，用于统计 HTTP 请求次数。
     *
     * @param callable|null $observer 观察器回调
     * @return void
     * 无副作用。
     */
    public function setRequestAttemptObserver(?callable $observer): void
    {
        $this->requestAttemptObserver = $observer;
    }

    /**
     * 设置请求限速（每秒请求数）。
     *
     * @param float|null $rateLimitPerSec 每秒请求上限
     * @return void
     * 无副作用。
     */
    public function setRequestRateLimitPerSec(?float $rateLimitPerSec): void
    {
        $rateLimit = $rateLimitPerSec === null ? null : max(0.0, $rateLimitPerSec);
        if ($rateLimit !== null && $rateLimit <= 0) {
            $rateLimit = null;
        }

        $this->requestRateLimitPerSec = $rateLimit;
        $this->lastRequestAtMs = null;
    }

    /**
     * 使用访客 cookie 请求并处理拦截场景。
     *
     * @param string $path 请求路径
     * @param array<string, mixed> $query Query 参数
     * @return string HTML 内容
     * @throws NgaRequestException 访客请求失败
     * 无副作用。
     */
    private function fetchWithGuest(string $path, array $query): string
    {
        $this->ensureGuestCookies();

        $response = $this->request($path, $query, true);
        if ($this->isGuestBlocked($response)) {
            // 遇到访客拦截时刷新 cookie 后重试一次
            $this->refreshGuestCookies();
            $response = $this->request($path, $query, true);
            if ($this->isGuestBlocked($response)) {
                throw $this->buildGuestBlockedException($response);
            }
        }

        if (!$response->successful()) {
            throw $this->buildStatusFailureException($response);
        }

        return $response->body();
    }

    /**
     * 确保已加载访客 cookie。
     *
     * @return void
     * @throws NgaRequestException 访客请求失败
     * 无副作用。
     */
    private function ensureGuestCookies(): void
    {
        if ($this->guestJs !== null && $this->lastVisit !== null) {
            return;
        }

        $this->refreshGuestCookies();
    }

    /**
     * 刷新访客 cookie，必要时重试一次。
     *
     * @return void
     * @throws NgaRequestException 访客请求失败
     * 无副作用。
     */
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

        if ($this->isGuestBlocked($response)) {
            throw $this->buildGuestBlockedException($response);
        }

        if (!$response->successful()) {
            throw $this->buildStatusFailureException($response);
        }

        $guestJs = $this->extractGuestJs($response->body());
        $lastVisit = $this->extractCookieValue($response, 'lastvisit');

        if ($guestJs === null || $lastVisit === null) {
            throw new NgaRequestException(
                CrawlErrorSummary::GUEST_BLOCKED,
                $response->status(),
                'Unable to acquire guest cookies from NGA response.'
            );
        }

        $this->guestJs = $guestJs;
        $this->lastVisit = $lastVisit;
    }

    /**
     * 发送 HTTP 请求并执行重试与退避逻辑。
     *
     * @param string $path 请求路径
     * @param array<string, mixed> $query Query 参数
     * @param bool $withCookies 是否携带访客 cookie
     * @return Response HTTP 响应
     * @throws NgaRequestException 连接失败或重试耗尽
     * 无副作用。
     */
    private function request(string $path, array $query, bool $withCookies): Response
    {
        $headers = $this->defaultHeaders();
        $http = Http::withHeaders($headers)
            ->connectTimeout($this->resolveConnectTimeout())
            ->timeout($this->resolveTimeout());

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
        $maxAttempts = max(1, $this->resolveRetryTimes() + 1);
        $attempt = 1;
        $lastException = null;
        $lastResponse = null;

        while ($attempt <= $maxAttempts) {
            $queryWithRand = $this->appendRand($query);
            $this->applyRateLimitIfNeeded();
            $this->recordRequestAttempt();

            // 记录完整 curl，便于回放定位拦截/访问异常
            $this->logCurlCommand($url, $queryWithRand, $headers, $withCookies);
            $start = microtime(true);

            try {
                $response = $http->get($url, $queryWithRand);
                $durationMs = (int) round((microtime(true) - $start) * 1000);
                $lastResponse = $response;

                Log::info('NGA HTTP request finished', [
                    'method' => 'GET',
                    'url' => $url,
                    'query' => $queryWithRand,
                    'with_cookies' => $withCookies,
                    'headers' => $headers,
                    'status' => $response->status(),
                    'duration_ms' => $durationMs,
                    'body_bytes' => strlen((string) $response->body()),
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                ]);

                if ($response->successful()) {
                    return $response;
                }

                $status = $response->status();
                $shouldRetry = $this->shouldRetryByStatus($status);
                if (!$shouldRetry || $attempt >= $maxAttempts) {
                    return $response;
                }

                $delayMs = $this->resolveBackoffDelayMs($attempt, $response);
                $this->sleepBeforeRetry($delayMs);
            } catch (ConnectionException $exception) {
                $durationMs = (int) round((microtime(true) - $start) * 1000);
                $lastException = $exception;

                Log::error('NGA HTTP request failed', [
                    'method' => 'GET',
                    'url' => $url,
                    'query' => $queryWithRand,
                    'with_cookies' => $withCookies,
                    'headers' => $headers,
                    'duration_ms' => $durationMs,
                    'error' => $exception->getMessage(),
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                ]);

                if ($attempt >= $maxAttempts) {
                    break;
                }

                $delayMs = $this->resolveBackoffDelayMs($attempt, null);
                $this->sleepBeforeRetry($delayMs);
            } catch (Throwable $exception) {
                $durationMs = (int) round((microtime(true) - $start) * 1000);
                $lastException = $exception;

                Log::error('NGA HTTP request failed', [
                    'method' => 'GET',
                    'url' => $url,
                    'query' => $queryWithRand,
                    'with_cookies' => $withCookies,
                    'headers' => $headers,
                    'duration_ms' => $durationMs,
                    'error' => $exception->getMessage(),
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                ]);

                if ($attempt >= $maxAttempts) {
                    break;
                }

                $delayMs = $this->resolveBackoffDelayMs($attempt, null);
                $this->sleepBeforeRetry($delayMs);
            }

            $attempt++;
        }

        if ($lastResponse instanceof Response) {
            return $lastResponse;
        }

        if ($lastException instanceof Throwable) {
            $summaryToken = $this->resolveExceptionSummaryToken($lastException);
            throw new NgaRequestException($summaryToken, null, $lastException->getMessage(), $lastException);
        }

        throw new NgaRequestException(
            CrawlErrorSummary::UNKNOWN_ERROR,
            null,
            'NGA request failed without response.'
        );
    }

    /**
     * 追加 rand 参数，规避源站缓存影响。
     *
     * @param array<string, mixed> $query 原始 Query 参数
     * @return array<string, mixed> 增补后的 Query 参数
     * 无副作用。
     */
    private function appendRand(array $query): array
    {
        $query['rand'] = $this->randomRand();

        return $query;
    }

    /**
     * 记录一次请求尝试，用于统计。
     *
     * @return void
     * 无副作用。
     */
    private function recordRequestAttempt(): void
    {
        if ($this->requestAttemptObserver === null) {
            return;
        }

        ($this->requestAttemptObserver)();
    }

    /**
     * 执行进程内限速，确保不超过每秒请求上限。
     *
     * @return void
     * 无副作用。
     */
    private function applyRateLimitIfNeeded(): void
    {
        if ($this->requestRateLimitPerSec === null) {
            return;
        }

        $minIntervalMs = 1000 / $this->requestRateLimitPerSec;
        $nowMs = microtime(true) * 1000;

        if ($this->lastRequestAtMs !== null) {
            $nextAllowedAtMs = $this->lastRequestAtMs + $minIntervalMs;
            $sleepMs = max(0.0, $nextAllowedAtMs - $nowMs);
            if ($sleepMs > 0) {
                usleep((int) round($sleepMs * 1000));
            }
        }

        $this->lastRequestAtMs = microtime(true) * 1000;
    }

    /**
     * 判断响应状态码是否需要重试。
     *
     * @param int $status HTTP 状态码
     * @return bool 是否可重试
     * 无副作用。
     */
    private function shouldRetryByStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * 计算退避延迟（毫秒）。
     *
     * @param int $attempt 当前重试次数（从 1 开始）
     * @param Response|null $response HTTP 响应（可为空）
     * @return int 退避延迟（毫秒）
     * 无副作用。
     */
    private function resolveBackoffDelayMs(int $attempt, ?Response $response): int
    {
        if ($response instanceof Response && $response->status() === 429) {
            $retryAfterMs = $this->resolveRetryAfterDelayMs($response);
            if ($retryAfterMs !== null) {
                return $retryAfterMs;
            }
        }

        $baseDelayMs = $this->resolveRetryDelayMs();
        $exponentialDelayMs = (int) round($baseDelayMs * (2 ** max(0, $attempt - 1)));
        $cappedDelayMs = min($exponentialDelayMs, self::DEFAULT_RETRY_MAX_DELAY_MS);

        $jitterMs = $baseDelayMs > 0 ? random_int(0, $baseDelayMs) : 0;

        return $cappedDelayMs + $jitterMs;
    }

    /**
     * 解析 Retry-After 响应头为毫秒延迟。
     *
     * @param Response $response HTTP 响应
     * @return int|null 解析结果（毫秒），无法解析时返回 null
     * 无副作用。
     */
    private function resolveRetryAfterDelayMs(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');
        if (is_array($retryAfter)) {
            $retryAfter = $retryAfter[0] ?? null;
        }
        if ($retryAfter === null) {
            return null;
        }

        $seconds = is_numeric($retryAfter) ? (int) $retryAfter : null;
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        return $seconds * 1000;
    }

    /**
     * 在重试前进行睡眠等待。
     *
     * @param int $delayMs 延迟毫秒
     * @return void
     * 无副作用。
     */
    private function sleepBeforeRetry(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        usleep($delayMs * 1000);
    }

    /**
     * 基于状态码构建失败异常。
     *
     * @param Response $response HTTP 响应
     * @return NgaRequestException
     * 无副作用。
     */
    private function buildStatusFailureException(Response $response): NgaRequestException
    {
        $status = $response->status();
        $summaryToken = $this->resolveStatusSummaryToken($status);

        return new NgaRequestException($summaryToken, $status, "NGA request failed: {$status}");
    }

    /**
     * 构建访客拦截异常。
     *
     * @param Response $response HTTP 响应
     * @return NgaRequestException
     * 无副作用。
     */
    private function buildGuestBlockedException(Response $response): NgaRequestException
    {
        $status = $response->status();
        $statusCode = $status > 0 ? $status : null;

        return new NgaRequestException(CrawlErrorSummary::GUEST_BLOCKED, $statusCode, 'NGA guest blocked');
    }

    /**
     * 将状态码映射为错误摘要 token。
     *
     * @param int $status HTTP 状态码
     * @return string 错误摘要 token
     * 无副作用。
     */
    private function resolveStatusSummaryToken(int $status): string
    {
        if ($status === 429) {
            return CrawlErrorSummary::HTTP_429;
        }

        if ($status === 408) {
            return CrawlErrorSummary::HTTP_TIMEOUT;
        }

        if ($status >= 500) {
            return CrawlErrorSummary::HTTP_5XX;
        }

        if ($status >= 400) {
            return CrawlErrorSummary::HTTP_4XX;
        }

        return CrawlErrorSummary::UNKNOWN_ERROR;
    }

    /**
     * 将异常映射为错误摘要 token。
     *
     * @param Throwable $exception 异常对象
     * @return string 错误摘要 token
     * 无副作用。
     */
    private function resolveExceptionSummaryToken(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        $isTimeout = str_contains($message, 'timed out') || str_contains($message, 'timeout');

        if ($isTimeout) {
            return CrawlErrorSummary::HTTP_TIMEOUT;
        }

        return CrawlErrorSummary::HTTP_CONNECT_ERROR;
    }

    /**
     * 生成默认请求头。
     *
     * @return array<string, string>
     * 无副作用。
     */
    private function defaultHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => $this->refererUrl(),
        ];
    }

    /**
     * 生成完整请求 URL。
     *
     * @param string $path 请求路径
     * @return string 完整 URL
     * 无副作用。
     */
    private function buildUrl(string $path): string
    {
        return rtrim(self::BASE_URL, '/').'/'.$path;
    }

    /**
     * 获取请求 Referer URL。
     *
     * @return string
     * 无副作用。
     */
    private function refererUrl(): string
    {
        return self::BASE_URL.'/thread.php?fid='.$this->forumId;
    }

    /**
     * 生成随机 rand 参数（100~999）。
     *
     * @return int
     * @throws \Exception random_int 失败时抛出
     * 无副作用。
     */
    private function randomRand(): int
    {
        // rand 要求 100~999
        return random_int(100, 999);
    }

    /**
     * 判断响应是否触发访客拦截。
     *
     * @param Response $response HTTP 响应
     * @return bool 是否被拦截
     * 无副作用。
     */
    private function isGuestBlocked(Response $response): bool
    {
        if ($response->status() === 403) {
            return true;
        }

        $body = $response->body();

        return str_contains($body, '访客不能直接访问') || str_contains($body, 'ERROR:15');
    }

    /**
     * 从响应体中提取 guestJs。
     *
     * @param string $body 响应体
     * @return string|null guestJs 值
     * 无副作用。
     */
    private function extractGuestJs(string $body): ?string
    {
        // 从响应体里提取 guestJs
        if (preg_match('/guestJs=([0-9]+_[0-9a-z]+)/i', $body, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * 从 Set-Cookie 中提取指定 cookie。
     *
     * @param Response $response HTTP 响应
     * @param string $name cookie 名称
     * @return string|null cookie 值
     * 无副作用。
     */
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

    /**
     * 解析连接超时（秒）。
     *
     * @return float
     * 无副作用。
     */
    private function resolveConnectTimeout(): float
    {
        return $this->normalizePositiveFloat(env('NGA_HTTP_CONNECT_TIMEOUT', self::DEFAULT_CONNECT_TIMEOUT), self::DEFAULT_CONNECT_TIMEOUT);
    }

    /**
     * 解析请求超时（秒）。
     *
     * @return float
     * 无副作用。
     */
    private function resolveTimeout(): float
    {
        return $this->normalizePositiveFloat(env('NGA_HTTP_TIMEOUT', self::DEFAULT_TIMEOUT), self::DEFAULT_TIMEOUT);
    }

    /**
     * 解析重试次数（不含首次请求）。
     *
     * @return int
     * 无副作用。
     */
    private function resolveRetryTimes(): int
    {
        return $this->normalizePositiveInt(env('NGA_HTTP_RETRY_TIMES', self::DEFAULT_RETRY_TIMES), self::DEFAULT_RETRY_TIMES);
    }

    /**
     * 解析重试基准延迟（毫秒）。
     *
     * @return int
     * 无副作用。
     */
    private function resolveRetryDelayMs(): int
    {
        return $this->normalizeNonNegativeInt(env('NGA_HTTP_RETRY_DELAY_MS', self::DEFAULT_RETRY_DELAY_MS), self::DEFAULT_RETRY_DELAY_MS);
    }

    /**
     * 判断是否强制 IPv4。
     *
     * @return bool
     * 无副作用。
     */
    private function shouldForceIpv4(): bool
    {
        $value = env('NGA_FORCE_IPV4');
        if ($value === null) {
            return false;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $bool ?? false;
    }

    /**
     * 规范化正数浮点值。
     *
     * @param mixed $value 原始值
     * @param float $fallback 兜底值
     * @return float
     * 无副作用。
     */
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

    /**
     * 规范化正整数。
     *
     * @param mixed $value 原始值
     * @param int $fallback 兜底值
     * @return int
     * 无副作用。
     */
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

    /**
     * 规范化非负整数。
     *
     * @param mixed $value 原始值
     * @param int $fallback 兜底值
     * @return int
     * 无副作用。
     */
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

    /**
     * 记录 curl 命令，便于回放排查。
     *
     * @param string $url 请求 URL
     * @param array<string, mixed> $query Query 参数
     * @param array<string, string> $headers 请求头
     * @param bool $withCookies 是否携带 cookie
     * @return void
     * 副作用：写入 curl 日志文件。
     */
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

    /**
     * 构建 curl 命令字符串。
     *
     * @param string $url 请求 URL
     * @param array<string, mixed> $query Query 参数
     * @param array<string, string> $headers 请求头
     * @param bool $withCookies 是否携带 cookie
     * @return string
     * 无副作用。
     */
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

    /**
     * 解析 curl 日志落盘路径。
     *
     * @return string|null
     * 无副作用。
     */
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
