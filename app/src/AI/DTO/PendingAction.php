<?php

declare(strict_types=1);

namespace App\AI\DTO;

/**
 * Описание отложенной операции, которая ждёт подтверждения пользователя.
 * Хранится в Redis (PendingActionStore) с TTL 5 минут. Создаётся
 * tool'ом, требующим подтверждения; исполняется ConfirmationExecutor
 * при нажатии кнопки или текстовом «да».
 *
 * actionType — строковый тэг ('create_tasks_batch', 'cancel_task',
 * 'bulk_mark_done', 'bulk_snooze', 'bulk_set_priority', 'bulk_cancel').
 * payload — данные специфичные для actionType (списки task_ids,
 * списки raw_text задач, until_iso, priority и т.д.).
 */
final readonly class PendingAction
{
    public function __construct(
        public string $userId,
        public string $actionType,
        public string $description,
        public array $payload,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'action_type' => $this->actionType,
            'description' => $this->description,
            'payload' => $this->payload,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            userId: (string) ($data['user_id'] ?? ''),
            actionType: (string) ($data['action_type'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            payload: (array) ($data['payload'] ?? []),
            createdAt: new \DateTimeImmutable(
                (string) ($data['created_at'] ?? 'now'),
                new \DateTimeZone('UTC'),
            ),
        );
    }
}
