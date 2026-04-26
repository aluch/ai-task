<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /health — быстрая liveness/readiness-проверка для Caddy/Docker
 * healthcheck и внешнего мониторинга (Uptime Robot и т.п.).
 *
 * Проверяет: коннект к Postgres (SELECT 1) и к Redis (PING).
 * Без AI-вызовов — должно отвечать <100мс. На любую ошибку — 503.
 */
class HealthController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly string $redisUrl,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks = [
            'db' => $this->checkDb(),
            'redis' => $this->checkRedis(),
        ];

        $allOk = !in_array(false, array_map(fn ($r) => $r === 'ok', $checks), true);
        $status = $allOk ? 200 : 503;

        return new JsonResponse([
            'status' => $allOk ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $status);
    }

    private function checkDb(): string
    {
        try {
            $conn = $this->doctrine->getConnection();
            $conn->executeQuery('SELECT 1');

            return 'ok';
        } catch (\Throwable $e) {
            return 'fail: ' . mb_substr($e->getMessage(), 0, 120);
        }
    }

    private function checkRedis(): string
    {
        try {
            $parsed = parse_url($this->redisUrl);
            $host = $parsed['host'] ?? 'redis';
            $port = $parsed['port'] ?? 6379;
            $redis = new \Redis();
            $redis->connect($host, $port, 1.0);
            $pong = $redis->ping();
            $redis->close();
            // PHP redis возвращает true или строку '+PONG'/'PONG' в зависимости от версии.
            if ($pong === true || $pong === '+PONG' || $pong === 'PONG') {
                return 'ok';
            }

            return 'fail: unexpected ping response';
        } catch (\Throwable $e) {
            return 'fail: ' . mb_substr($e->getMessage(), 0, 120);
        }
    }
}
