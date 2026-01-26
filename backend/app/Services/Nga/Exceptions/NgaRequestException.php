<?php

namespace App\Services\Nga\Exceptions;

use RuntimeException;

/**
 * NGA HTTP 请求失败异常，用于携带审计摘要与状态码。
 */
class NgaRequestException extends RuntimeException
{
    /**
     * @param string $summaryToken 错误摘要 token
     * @param int|null $statusCode HTTP 状态码（连接失败时可能为空）
     * @param string $message 异常信息
     * @param \Throwable|null $previous 前置异常
     */
    public function __construct(
        private readonly string $summaryToken,
        private readonly ?int $statusCode,
        string $message,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 获取错误摘要 token。
     *
     * @return string
     * 无副作用。
     */
    public function getSummaryToken(): string
    {
        return $this->summaryToken;
    }

    /**
     * 获取 HTTP 状态码（若有）。
     *
     * @return int|null
     * 无副作用。
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
