<?php

declare(strict_types=1);

namespace App\Controller;

use App\Telegram\HandlerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Configuration;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/telegram/webhook/{secret} — production-режим Telegram bot.
 * Альтернатива long-polling (BotRunCommand). Активируется когда
 * TELEGRAM_MODE=webhook + setWebhook на стороне Telegram (см. bin/set-webhook.sh).
 *
 * Защита:
 *   1. Длинный random secret в URL (TELEGRAM_WEBHOOK_SECRET, ≥32 символа).
 *      Нет совпадения → 404.
 *   2. Доп. проверка заголовка X-Telegram-Bot-Api-Secret-Token (Telegram
 *      шлёт его если передать secret_token при setWebhook). Совпадает —
 *      запрос точно от Telegram.
 *
 * Логика обработки идентична polling: создаём Nutgram, регистрируем
 * handlers через HandlerRegistry, дёргаем processUpdate() с JSON-телом
 * запроса. Возвращаем 200 OK сразу (Telegram повторит при 5xx/timeout).
 */
class TelegramWebhookController
{
    public function __construct(
        private readonly HandlerRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly string $botToken,
        private readonly string $webhookSecret,
    ) {
    }

    #[Route(
        '/api/telegram/webhook/{secret}',
        name: 'telegram_webhook',
        methods: ['POST'],
        requirements: ['secret' => '[A-Za-z0-9_-]{16,128}'],
    )]
    public function __invoke(string $secret, Request $request): Response
    {
        // URL-secret check (constant-time чтобы не светить timing-side-channel).
        if ($this->webhookSecret === '' || !hash_equals($this->webhookSecret, $secret)) {
            return new Response('not found', 404);
        }

        // Опциональный header — если Telegram шлёт его (мы передаём secret_token
        // в setWebhook), сверяем тоже. Если заголовка нет — пропускаем (legacy).
        $headerSecret = $request->headers->get('X-Telegram-Bot-Api-Secret-Token', '');
        if ($headerSecret !== '' && !hash_equals($this->webhookSecret, $headerSecret)) {
            $this->logger->warning('Webhook: header secret mismatch');

            return new Response('forbidden', 403);
        }

        $payload = $request->getContent();
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            $this->logger->warning('Webhook: invalid JSON', ['preview' => mb_substr($payload, 0, 200)]);

            return new Response('bad request', 400);
        }

        try {
            $bot = new Nutgram($this->botToken, new Configuration(
                clientTimeout: 30,
            ));
            // Webhook-режим (без long polling). Update передаём вручную.
            $bot->setRunningMode(Webhook::class);
            $this->registry->register($bot);
            $bot->processUpdate(
                \SergiX44\Nutgram\Telegram\Types\Update\Update::fromArray($data),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Webhook handler failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            // 200 даже на ошибку — иначе Telegram будет повторять и спамить.
            // Ошибки видим в логах, действуем по ним.
            return new Response('ok', 200);
        }

        return new Response('ok', 200);
    }
}
