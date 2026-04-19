<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Redis-хранилище состояний пагинации. Каждая «сессия» — это один inline-список
 * (для /list, /done, /snooze, /block, /unblock, /deps). Задачи при листании
 * запрашиваются из БД свежими (по limit+offset), в Redis хранится только:
 *
 *   - user_id (проверка ownership в callback handler'е)
 *   - action (для какой команды сессия — нужен при кнопке «Поиск»)
 *   - filter (статусы, unblocked-flag — чтобы повторный запрос был консистентен)
 *   - total (сколько страниц, посчитано в момент создания сессии)
 *
 * TTL 1 час. Сами task_id не хранятся — между страницами возможен дрейф
 * (кто-то пометил задачу done, она исчезла из active), это нормально.
 *
 * Ключ также служит и для «Поиск»-reply prompt: при клике на search-кнопку
 * в тот же Redis под отдельным префиксом waiting_search: пишется соответствие
 * user_id → redis_key, на следующий текст пользователя читаем и подставляем.
 */
class PaginationStore
{
    private const TTL_SESSION = 3600;
    private const TTL_WAITING_SEARCH = 120;
    private const KEY_PREFIX_SESSION = 'page:';
    private const KEY_PREFIX_SEARCH = 'waiting_search:';

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
     * @param array<string, mixed> $filter
     */
    public function create(string $userId, string $action, array $filter, int $total): string
    {
        $key = bin2hex(random_bytes(6));
        $payload = json_encode([
            'user_id' => $userId,
            'action' => $action,
            'filter' => $filter,
            'total' => $total,
        ], \JSON_UNESCAPED_UNICODE);

        $this->redis->setex(self::KEY_PREFIX_SESSION . $key, self::TTL_SESSION, $payload);

        return $key;
    }

    /**
     * @return array{user_id: string, action: string, filter: array, total: int}|null
     */
    public function get(string $key): ?array
    {
        $raw = $this->redis->get(self::KEY_PREFIX_SESSION . $key);
        if ($raw === false || !is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public function delete(string $key): void
    {
        $this->redis->del(self::KEY_PREFIX_SESSION . $key);
    }

    /**
     * Запоминает, что пользователь нажал «Поиск» в рамках конкретной сессии
     * пагинации. Следующий текст от этого пользователя будет интерпретирован
     * как поисковый запрос. TTL 2 минуты — если юзер забыл, после таймаута
     * обычная обработка свободного текста возобновляется.
     */
    public function setWaitingSearch(int|string $telegramUserId, string $sessionKey): void
    {
        $this->redis->setex(
            self::KEY_PREFIX_SEARCH . (string) $telegramUserId,
            self::TTL_WAITING_SEARCH,
            $sessionKey,
        );
    }

    public function getWaitingSearch(int|string $telegramUserId): ?string
    {
        $val = $this->redis->get(self::KEY_PREFIX_SEARCH . (string) $telegramUserId);

        return is_string($val) ? $val : null;
    }

    public function clearWaitingSearch(int|string $telegramUserId): void
    {
        $this->redis->del(self::KEY_PREFIX_SEARCH . (string) $telegramUserId);
    }
}
