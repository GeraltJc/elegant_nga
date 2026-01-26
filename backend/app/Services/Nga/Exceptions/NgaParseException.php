<?php

namespace App\Services\Nga\Exceptions;

use RuntimeException;

/**
 * NGA 解析失败异常，用于区分列表/详情解析错误。
 */
class NgaParseException extends RuntimeException
{
    /**
     * @param string $summaryToken 错误摘要 token
     * @param string $message 异常信息
     * @param \Throwable|null $previous 前置异常
     */
    public function __construct(
        private readonly string $summaryToken,
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
}
