<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Передаёт blocked_task_uuid между step1 и step2 интерактивного /block и
 * /unblock flow. Callback_data ≤ 64 байта, два полных UUID туда не влезают,
 * поэтому blocked_uuid хранится в Redis, а в callback идёт короткий ключ
 * + полный blocker_uuid.
 */
class DependencyStateStore
{
    private const TTL_SECONDS = 600;
    private const KEY_PREFIX = 'dep:';

    private \Redis $redis;

    public function __construct(string $redisUrl)
    {
        $this->redis = new \Redis();

        $parsed = parse_url($redisUrl);
        $host = $parsed['host'] ?? 'redis';
        $port = $parsed['port'] ?? 6379;

        $this->redis->connect($host, $port);
    }

    public function store(string $blockedUuid): string
    {
        $key = bin2hex(random_bytes(6));
        $this->redis->setex(self::KEY_PREFIX . $key, self::TTL_SECONDS, $blockedUuid);

        return $key;
    }

    public function load(string $shortKey): ?string
    {
        $val = $this->redis->get(self::KEY_PREFIX . $shortKey);

        return is_string($val) ? $val : null;
    }

    public function delete(string $shortKey): void
    {
        $this->redis->del(self::KEY_PREFIX . $shortKey);
    }
}
