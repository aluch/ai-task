<?php

declare(strict_types=1);

namespace App\AI\DTO;

final readonly class AssistantResult
{
    /**
     * @param string[] $toolsCalled имена вызванных tools в порядке вызова
     */
    public function __construct(
        public string $replyText,
        public array $toolsCalled,
        public int $inputTokens,
        public int $outputTokens,
        public int $iterations,
    ) {
    }
}
