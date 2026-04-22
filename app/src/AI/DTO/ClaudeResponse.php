<?php

declare(strict_types=1);

namespace App\AI\DTO;

final readonly class ClaudeResponse
{
    public function __construct(
        public string $text,
        public string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheCreationInputTokens,
        public int $cacheReadInputTokens,
        public array $data,
    ) {
    }
}
