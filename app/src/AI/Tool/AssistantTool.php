<?php

declare(strict_types=1);

namespace App\AI\Tool;

use App\Entity\User;

interface AssistantTool
{
    /**
     * Имя tool'а, которое видит Claude (snake_case).
     */
    public function getName(): string;

    /**
     * Описание tool'а для Claude — по нему модель решает когда его вызывать.
     */
    public function getDescription(): string;

    /**
     * JSON Schema входных параметров. Возвращается как PHP-массив,
     * сериализуется в JSON при передаче в API.
     */
    public function getInputSchema(): array;

    /**
     * Исполняет tool. Любое исключение ловится ассистентом и превращается
     * в ToolResult(success=false) — так Claude получает понятную ошибку
     * вместо 500 у пользователя.
     *
     * @param array<string, mixed> $input распаршенный input от Claude
     */
    public function execute(User $user, array $input): ToolResult;
}
