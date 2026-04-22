<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTO\HistoryMessage;
use App\Entity\User;

/**
 * Redis-хранилище истории диалога Ассистента по пользователю.
 *
 * Окно — последние 10 сообщений (sliding window), TTL — 30 минут
 * с последней активности (каждый append продлевает EXPIRE).
 *
 * Ключ: conversation:<user_uuid>. Значение — JSON с массивом
 * сообщений и last_activity_at. В prod Assistant читает историю
 * перед вызовом Claude, смешивает с новым сообщением и передаёт в
 * messages API; после ответа дописывает оба сообщения в историю.
 */
class ConversationHistoryStore
{
    private const TTL_SECONDS = 1800;
    private const KEY_PREFIX = 'conversation:';
    private const MAX_MESSAGES = 10;

    private \Redis $redis;

    public function __construct(string $redisUrl)
    {
        $this->redis = new \Redis();

        $parsed = parse_url($redisUrl);
        $host = $parsed['host'] ?? 'redis';
        $port = $parsed['port'] ?? 6379;

        $this->redis->connect($host, $port);
    }

    public function append(User $user, HistoryMessage $message): void
    {
        $key = $this->keyFor($user);
        $messages = $this->readMessages($key);
        $messages[] = $message->toArray();

        if (count($messages) > self::MAX_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_MESSAGES);
        }

        $payload = [
            'user_id' => $user->getId()->toRfc4122(),
            'messages' => $messages,
            'last_activity_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format(\DateTimeInterface::ATOM),
        ];

        $this->redis->setex($key, self::TTL_SECONDS, json_encode($payload, \JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return HistoryMessage[]
     */
    public function get(User $user): array
    {
        $raw = $this->readMessages($this->keyFor($user));
        $result = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            try {
                $result[] = HistoryMessage::fromArray($item);
            } catch (\Throwable $e) {
                // битая запись — пропускаем, не даём уронить весь диалог
                continue;
            }
        }

        return $result;
    }

    public function clear(User $user): void
    {
        $this->redis->del($this->keyFor($user));
    }

    public function getSize(User $user): int
    {
        return count($this->readMessages($this->keyFor($user)));
    }

    private function keyFor(User $user): string
    {
        return self::KEY_PREFIX . $user->getId()->toRfc4122();
    }

    /**
     * @return array<int, mixed>
     */
    private function readMessages(string $key): array
    {
        $raw = $this->redis->get($key);
        if ($raw === false || !is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return is_array($decoded['messages'] ?? null) ? $decoded['messages'] : [];
    }
}
