<?php

declare(strict_types=1);

namespace App\Service\Subscription\Provider\YooKassa;

/**
 * Любая ошибка при работе с ЮKassa REST API — non-2xx ответ или transport.
 * Чтобы вызывающий мог отличить «сети нет» от «5xx ЮKassa» — проверяй
 * getCode() (HTTP status или 0 для transport).
 */
class YooKassaApiException extends \RuntimeException
{
}
