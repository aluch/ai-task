<?php

declare(strict_types=1);

namespace App\AI\DTO;

final readonly class TaskSuggestionDTO
{
    /**
     * @param SuggestedTask[] $suggestions
     * @param string|null $userSummary Короткое сообщение для пользователя (1-3 предложения)
     * @param string|null $internalReasoning Подробный анализ для логов (все отвергнутые с причинами)
     */
    public function __construct(
        public array $suggestions,
        public ?string $userSummary = null,
        public ?string $internalReasoning = null,
        public int $totalEstimatedMinutes = 0,
        public ?string $noMatchReason = null,
    ) {
    }
}
