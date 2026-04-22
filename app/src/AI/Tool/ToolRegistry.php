<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\Entity\User;
use Psr\Log\LoggerInterface;

class ToolRegistry
{
    /** @var array<string, AssistantTool> */
    private array $byName = [];

    /**
     * @param iterable<AssistantTool> $tools автосбор через DI tagged-iterator
     */
    public function __construct(
        iterable $tools,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($tools as $tool) {
            $this->byName[$tool->getName()] = $tool;
        }
        // Стабильный порядок — критично для prompt caching: любое изменение
        // байт в префиксе (в т.ч. перестановка tools) инвалидирует кэш.
        ksort($this->byName);
    }

    /**
     * Возвращает декларации всех tools в формате Anthropic API
     * (name / description / input_schema).
     *
     * @return array<int, array{name: string, description: string, input_schema: array}>
     */
    public function getAnthropicSchemas(): array
    {
        $schemas = [];
        foreach ($this->byName as $tool) {
            $schemas[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'input_schema' => $tool->getInputSchema(),
            ];
        }

        return $schemas;
    }

    /**
     * Исполняет tool по имени. Ошибки tool'а не пробрасываются — Claude
     * получает ToolResult с success=false и сам решает что сказать юзеру.
     */
    public function execute(string $name, User $user, array $input): ToolResult
    {
        $tool = $this->byName[$name] ?? null;
        if ($tool === null) {
            $this->logger->warning('Assistant requested unknown tool', ['name' => $name]);

            return ToolResult::error("Неизвестный инструмент: {$name}");
        }

        try {
            return $tool->execute($user, $input);
        } catch (\Throwable $e) {
            $this->logger->error('Tool execution failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolResult::error('Ошибка при выполнении: ' . $e->getMessage());
        }
    }
}
