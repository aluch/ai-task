<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Subscription\Provider\YooKassa\YooKassaIpAllowlist;
use App\Service\Subscription\Recurring\RebillWebhookProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/yookassa/webhook — HTTP-уведомления от ЮKassa о результате
 * recurring-списания.
 *
 * Защита — только IP allowlist. ЮKassa не подписывает webhook'и
 * (см. https://yookassa.ru/developers/using-api/webhooks).
 *
 * Идемпотентность: RebillWebhookProcessor проверяет статус attempt'а
 * (не Pending → no-op). Защита от дубликатов на уровне БД — partial
 * UNIQUE по recurring_attempts.external_payment_id.
 *
 * ВСЕГДА возвращаем 200 (даже на внутренние ошибки) — иначе ЮKassa
 * будет ретраить и забивать наш endpoint. Ошибки видим в логах.
 *
 * Для запуска: в личном кабинете ЮKassa → Интеграция → HTTP-уведомления:
 *   - URL: https://${DOMAIN}/api/yookassa/webhook
 *   - События: payment.succeeded, payment.canceled
 * Зарегистрировать дважды (для test- и live-магазинов).
 */
class YooKassaWebhookController
{
    public function __construct(
        private readonly YooKassaIpAllowlist $allowlist,
        private readonly RebillWebhookProcessor $processor,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/api/yookassa/webhook',
        name: 'yookassa_webhook',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $ip = $request->getClientIp();
        if (!$this->allowlist->isAllowed($ip)) {
            $this->logger->warning('YooKassa webhook: untrusted IP', ['ip' => $ip]);

            return new Response('forbidden', 403);
        }

        $body = $request->getContent();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->logger->warning('YooKassa webhook: invalid JSON', [
                'preview' => mb_substr($body, 0, 200),
            ]);
            // Возвращаем 200 — повтор тут не поможет.
            return new Response('ok', 200);
        }

        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $result = $this->processor->process($data, $now);
            $this->logger->info('YooKassa webhook processed', ['result' => $result]);
        } catch (\Throwable $e) {
            $this->logger->error('YooKassa webhook processing failed', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return new Response('ok', 200);
    }
}
