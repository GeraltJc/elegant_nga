<?php

namespace App\Services\Nga;

/**
 * 抓取错误摘要枚举与格式化工具，用于统一审计错误口径。
 */
final class CrawlErrorSummary
{
    /**
     * HTTP 429：触发限速/拒绝服务的失败类型。
     */
    public const HTTP_429 = 'http_429';

    /**
     * HTTP 5xx：源站或网关异常导致的失败类型。
     */
    public const HTTP_5XX = 'http_5xx';

    /**
     * HTTP 4xx：除 429/403 之外的客户端错误。
     */
    public const HTTP_4XX = 'http_4xx';

    /**
     * HTTP 超时：响应超时或 408 场景。
     */
    public const HTTP_TIMEOUT = 'http_timeout';

    /**
     * HTTP 连接失败：DNS/连接不可达等网络错误。
     */
    public const HTTP_CONNECT_ERROR = 'http_connect_error';

    /**
     * 访客拦截：需要刷新 guest cookie 的失败类型。
     */
    public const GUEST_BLOCKED = 'guest_blocked';

    /**
     * 列表解析失败：列表页结构异常或解析错误。
     */
    public const PARSE_LIST_FAILED = 'parse_list_failed';

    /**
     * 详情解析失败：主题详情页结构异常或解析错误。
     */
    public const PARSE_THREAD_FAILED = 'parse_thread_failed';

    /**
     * 入库失败：写入数据库时发生异常。
     */
    public const DB_WRITE_FAILED = 'db_write_failed';

    /**
     * 未知错误：无法归类的失败类型。
     */
    public const UNKNOWN_ERROR = 'unknown_error';

    /**
     * 将错误摘要与短消息拼接，控制长度不超过 200 字符。
     *
     * @param string $summaryToken 错误摘要 token
     * @param string|null $message 可选补充信息（将被压缩与截断）
     * @return string 可直接写入 error_summary 的结果
     * 无副作用。
     */
    public static function formatWithMessage(string $summaryToken, ?string $message): string
    {
        $trimmedMessage = trim((string) $message);
        if ($trimmedMessage === '') {
            return $summaryToken;
        }

        $normalizedMessage = preg_replace('/\\s+/u', ' ', $trimmedMessage) ?? $trimmedMessage;
        $suffix = ';msg='.$normalizedMessage;
        $maxLength = 200;
        $availableLength = $maxLength - mb_strlen($summaryToken, 'UTF-8');

        if ($availableLength <= 0) {
            return $summaryToken;
        }

        return $summaryToken.mb_substr($suffix, 0, $availableLength, 'UTF-8');
    }
}
