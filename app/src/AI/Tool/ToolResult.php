<?php

declare(strict_types=1);

namespace App\AI\Tool;

final readonly class ToolResult
{
    /**
     * @param array<string, mixed>|null $metadata данные для логирования / аудита
     *   (например, 'task_id' => uuid). Не передаётся обратно в Claude.
     */
    public function __construct(
        public bool $success,
        public string $content,
        public ?array $metadata = null,
    ) {
    }

    public static function ok(string $content, ?array $metadata = null): self
    {
        return new self(true, $content, $metadata);
    }

    public static function error(string $content, ?array $metadata = null): self
    {
        return new self(false, $content, $metadata);
    }
}
