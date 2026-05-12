<?php

declare(strict_types=1);

namespace App\Service\Subscription\Provider\YooKassa;

/**
 * Конфиг для интеграции с ЮKassa через Telegram Payments. Хранит набор
 * credentials под два режима (test / live) и отдаёт текущие значения
 * в зависимости от YOOKASSA_MODE.
 *
 * Fail-fast в конструкторе: если режим не test/live — InvalidArgumentException.
 * Пустые credentials для активного режима — допустимы (соответствующие
 * вызовы потом ругнутся в Telegram API). Это сделано чтобы контейнер
 * мог нормально стартовать в окружении где платежи ещё не сконфигурированы
 * (smoke, локалка без YooKassa).
 *
 * Secret key пока в коде не используется — он для S5/S6 (auto-rebill,
 * refund через ЮKassa REST API). Прописан заранее, чтобы при добавлении
 * функционала не делать отдельный deploy с env-изменениями.
 */
class YooKassaConfig
{
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    public function __construct(
        private readonly string $mode,
        private readonly string $testProviderToken,
        private readonly string $liveProviderToken,
        private readonly string $testShopId,
        private readonly string $liveShopId,
        private readonly string $testSecretKey,
        private readonly string $liveSecretKey,
    ) {
        if ($mode !== self::MODE_TEST && $mode !== self::MODE_LIVE) {
            throw new \InvalidArgumentException(
                "YOOKASSA_MODE must be 'test' or 'live', got: '{$mode}'",
            );
        }
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isTestMode(): bool
    {
        return $this->mode === self::MODE_TEST;
    }

    public function getProviderToken(): string
    {
        return $this->isTestMode() ? $this->testProviderToken : $this->liveProviderToken;
    }

    public function getShopId(): string
    {
        return $this->isTestMode() ? $this->testShopId : $this->liveShopId;
    }

    public function getSecretKey(): string
    {
        return $this->isTestMode() ? $this->testSecretKey : $this->liveSecretKey;
    }

    /**
     * Готов ли вообще принимать платежи. Если provider_token пуст —
     * sendInvoice провалится с ошибкой Telegram API, поэтому имеет
     * смысл проверить заранее в UpgradePayCallbackHandler.
     */
    public function isConfigured(): bool
    {
        return $this->getProviderToken() !== '';
    }
}
