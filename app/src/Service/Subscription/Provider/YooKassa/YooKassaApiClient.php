<?php

declare(strict_types=1);

namespace App\Service\Subscription\Provider\YooKassa;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * REST API клиент к ЮKassa (https://api.yookassa.ru/v3). Используется
 * для двух сценариев S5:
 *
 * 1. {@see getPayment} — после первого Telegram Payment получаем по id
 *    объект payment с payment_method.id (токен карты), чтобы сохранить
 *    в Subscription::savedPaymentMethodId для будущих recurring-списаний.
 *
 * 2. {@see createRecurringPayment} — POST /payments с payment_method_id
 *    и Idempotence-Key (защита от двойных списаний при retries). ЮKassa
 *    отвечает 200 «принято в обработку», реальный исход прилетает на
 *    {@see \App\Controller\YooKassaWebhookController} как webhook.
 *
 * Auth: Basic, login = shopId, password = secretKey (см. YooKassaConfig).
 * Если креды пусты — методы кидают LogicException; вызывающий должен
 * проверить YooKassaConfig::isConfigured() перед использованием.
 *
 * Не логируем заголовки (содержат Basic-auth с secret), не логируем
 * response целиком — только статус/id платежа/error_code.
 */
class YooKassaApiClient
{
    public const BASE_URL = 'https://api.yookassa.ru/v3';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly YooKassaConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * GET /payments/{id} — детали платежа, включая payment_method.id.
     *
     * @return array<string, mixed>
     * @throws YooKassaApiException
     */
    public function getPayment(string $paymentId): array
    {
        $this->ensureConfigured();

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/payments/' . $paymentId, [
                'auth_basic' => [$this->config->getShopId(), $this->config->getSecretKey()],
                'timeout' => 15,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('YooKassa getPayment failed (transport)', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            throw new YooKassaApiException('Transport error: ' . $e->getMessage(), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('YooKassa getPayment non-2xx', [
                'payment_id' => $paymentId,
                'status' => $status,
                'code' => $data['code'] ?? null,
            ]);
            throw new YooKassaApiException(
                "Non-2xx response: {$status} " . ($data['code'] ?? ''),
                $status,
            );
        }

        return $data;
    }

    /**
     * POST /payments — recurring-списание по сохранённому payment_method.
     * Возвращает ответ ЮKassa («pending» обычно, потом webhook).
     *
     * @return array<string, mixed>
     * @throws YooKassaApiException
     */
    public function createRecurringPayment(
        string $paymentMethodId,
        int $amountMinor,
        string $description,
        string $idempotenceKey,
        string $currency = InvoicePayloadBuilder::CURRENCY,
        array $metadata = [],
    ): array {
        $this->ensureConfigured();

        $body = [
            'amount' => [
                'value' => number_format($amountMinor / 100, 2, '.', ''),
                'currency' => $currency,
            ],
            'payment_method_id' => $paymentMethodId,
            'description' => $description,
            'capture' => true,
            'metadata' => $metadata,
        ];

        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . '/payments', [
                'auth_basic' => [$this->config->getShopId(), $this->config->getSecretKey()],
                'headers' => [
                    'Idempotence-Key' => $idempotenceKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('YooKassa createRecurringPayment failed (transport)', [
                'payment_method_id' => $paymentMethodId,
                'amount_minor' => $amountMinor,
                'error' => $e->getMessage(),
            ]);
            throw new YooKassaApiException('Transport error: ' . $e->getMessage(), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->warning('YooKassa createRecurringPayment non-2xx', [
                'payment_method_id' => $paymentMethodId,
                'status' => $status,
                'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
            ]);
            throw new YooKassaApiException(
                "Non-2xx response: {$status} " . ($data['code'] ?? ''),
                $status,
            );
        }

        $this->logger->info('YooKassa recurring payment created', [
            'payment_id' => $data['id'] ?? null,
            'status' => $data['status'] ?? null,
            'amount_minor' => $amountMinor,
        ]);

        return $data;
    }

    /**
     * DELETE /payment_methods/{id} — отвязать сохранённую карту от магазина.
     * Используется при полной отмене подписки или смене карты пользователем.
     *
     * Безопасно: если карты уже нет, ЮKassa отвечает 404, мы это глотаем.
     */
    public function removePaymentMethod(string $paymentMethodId): void
    {
        $this->ensureConfigured();

        try {
            $response = $this->httpClient->request(
                'DELETE',
                self::BASE_URL . '/payment_methods/' . $paymentMethodId,
                [
                    'auth_basic' => [$this->config->getShopId(), $this->config->getSecretKey()],
                    'timeout' => 15,
                ],
            );
            $status = $response->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->warning('YooKassa removePaymentMethod failed (transport)', [
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage(),
            ]);

            return;
        }
        if ($status !== 200 && $status !== 204 && $status !== 404) {
            $this->logger->warning('YooKassa removePaymentMethod non-2xx', [
                'payment_method_id' => $paymentMethodId,
                'status' => $status,
            ]);
        }
    }

    private function ensureConfigured(): void
    {
        if (!$this->config->isConfigured()) {
            throw new \LogicException('YooKassa API: provider_token не сконфигурирован');
        }
        if ($this->config->getShopId() === '' || $this->config->getSecretKey() === '') {
            throw new \LogicException(
                'YooKassa API: shop_id или secret_key не сконфигурирован '
                . '(для S5 нужны оба, не только provider_token).',
            );
        }
    }
}
