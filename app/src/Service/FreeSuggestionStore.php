<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Хранит промежуточное состояние предложения /free в Redis: список task_ids,
 * исходные параметры запроса (минуты, контекст) и накопленные exclude'ы от
 * rerolls. Callback_data в Telegram ограничен 64 байтами, а несколько UUID
 * туда не помещаются — поэтому state хранится отдельно, а в callback_data
 * идёт только короткий ключ.
 */
class FreeSuggestionStore
{
    private const TTL_SECONDS = 3600;
    private const KEY_PREFIX = 'free:';

    private \Redis $redis;

    public function __construct(string $redisUrl)
    {
        $this->redis = new \Redis();

        $parsed = parse_url($redisUrl);
        $host = $parsed['host'] ?? 'redis';
        $port = $parsed['port'] ?? 6379;

        $this->redis->connect($host, $port);
    }

    /**
     * @param string[] $taskIds
     * @param string[] $excludedIds
     */
    public function save(
        string $userId,
        array $taskIds,
        int $minutes,
        ?string $context,
        array $excludedIds,
        int $rerollCount,
    ): string {
        $key = self::KEY_PREFIX . bin2hex(random_bytes(6));

        $payload = json_encode([
            'user_id' => $userId,
            'task_ids' => $taskIds,
            'minutes' => $minutes,
            'context' => $context,
            'excluded_ids' => $excludedIds,
            'reroll_count' => $rerollCount,
        ]);

        $this->redis->setex($key, self::TTL_SECONDS, $payload);

        return substr($key, strlen(self::KEY_PREFIX));
    }

    /**
     * @return array{user_id: string, task_ids: string[], minutes: int, context: ?string, excluded_ids: string[], reroll_count: int}|null
     */
    public function load(string $shortKey): ?array
    {
        $raw = $this->redis->get(self::KEY_PREFIX . $shortKey);
        if ($raw === false || !is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    public function delete(string $shortKey): void
    {
        $this->redis->del(self::KEY_PREFIX . $shortKey);
    }
}
