<?php

declare(strict_types=1);

namespace App\AI\DTO;

final readonly class TaskSuggestionDTO
{
    /**
     * @param SuggestedTask[] $suggestions
     */
    public function __construct(
        public array $suggestions,
        public ?string $reasoning = null,
        public int $totalEstimatedMinutes = 0,
        public ?string $noMatchReason = null,
    ) {
    }
}
