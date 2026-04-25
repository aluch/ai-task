<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTO\PendingAction;
use App\Entity\User;

/**
 * Redis-хранилище отложенных операций Ассистента (требующих
 * подтверждения). Ключ pending_action:<short_id>, TTL 5 минут.
 * short_id — 8 hex-символов чтобы влезать в callback_data Telegram
 * (лимит 64 байта; «confirm:<8hex>:yes» = 19 байт).
 */
class PendingActionStore
{
    private const TTL_SECONDS = 300;
    private const KEY_PREFIX = 'pending_action:';
    private const USER_INDEX_PREFIX = 'pending_action_user:';

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
     * Сохраняет PendingAction и возвращает короткий confirmation_id.
     * Также обновляет индекс по user'у: latestForUser() нужен для
     * текстовых «да/нет» (когда пользователь не нажимает кнопку).
     */
    public function create(User $user, PendingAction $action): string
    {
        $shortId = bin2hex(random_bytes(4));
        $key = self::KEY_PREFIX . $shortId;

        $this->redis->setex(
            $key,
            self::TTL_SECONDS,
            json_encode($action->toArray(), \JSON_UNESCAPED_UNICODE),
        );

        // Индекс «последний pending у этого user'а» — для текстового подтверждения.
        $userKey = self::USER_INDEX_PREFIX . $user->getId()->toRfc4122();
        $this->redis->setex($userKey, self::TTL_SECONDS, $shortId);

        return $shortId;
    }

    public function get(string $confirmationId): ?PendingAction
    {
        $raw = $this->redis->get(self::KEY_PREFIX . $confirmationId);
        if ($raw === false || !is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return PendingAction::fromArray($decoded);
    }

    /**
     * Достать и удалить (атомарно через GETDEL если поддерживается).
     */
    public function consume(string $confirmationId): ?PendingAction
    {
        $action = $this->get($confirmationId);
        if ($action === null) {
            return null;
        }
        $this->redis->del(self::KEY_PREFIX . $confirmationId);
        // Чистим user-index если он указывает на этот же id
        $userKey = self::USER_INDEX_PREFIX . $action->userId;
        $current = $this->redis->get($userKey);
        if (is_string($current) && $current === $confirmationId) {
            $this->redis->del($userKey);
        }

        return $action;
    }

    /**
     * Удалить все pending'и пользователя. Удаляем индекс и сам action,
     * на который он указывает (если ещё актуален).
     */
    public function clear(User $user): void
    {
        $userKey = self::USER_INDEX_PREFIX . $user->getId()->toRfc4122();
        $shortId = $this->redis->get($userKey);
        if (is_string($shortId) && $shortId !== '') {
            $this->redis->del(self::KEY_PREFIX . $shortId);
        }
        $this->redis->del($userKey);
    }

    /**
     * Последний pending у user'а — для обработки текстового «да».
     */
    public function latestForUser(User $user): ?string
    {
        $userKey = self::USER_INDEX_PREFIX . $user->getId()->toRfc4122();
        $raw = $this->redis->get($userKey);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
