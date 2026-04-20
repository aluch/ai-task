<?php

declare(strict_types=1);

namespace App\AI\DTO;

use App\Enum\TaskPriority;

final readonly class ParsedTaskDTO
{
    /**
     * @param string[] $contextCodes
     */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?\DateTimeImmutable $deadline = null,
        public ?int $estimatedMinutes = null,
        public TaskPriority $priority = TaskPriority::MEDIUM,
        public array $contextCodes = [],
        public ?string $parserNotes = null,
        public ?int $remindBeforeDeadlineMinutes = null,
    ) {
    }
}
