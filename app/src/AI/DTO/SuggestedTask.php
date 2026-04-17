<?php

declare(strict_types=1);

namespace App\AI\DTO;

final readonly class SuggestedTask
{
    public function __construct(
        public string $taskId,
        public int $order,
        public ?string $tip = null,
        public ?int $estimatedMinutes = null,
    ) {
    }
}
