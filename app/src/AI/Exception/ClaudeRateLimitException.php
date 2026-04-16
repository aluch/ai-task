<?php

declare(strict_types=1);

namespace App\AI\Exception;

class ClaudeRateLimitException extends \RuntimeException
{
    public function __construct(
        string $message = 'Rate limited',
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $previous);
    }
}
