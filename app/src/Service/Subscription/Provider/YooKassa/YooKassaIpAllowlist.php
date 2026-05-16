<?php

declare(strict_types=1);

namespace App\Service\Subscription\Provider\YooKassa;

/**
 * Список IP, с которых ЮKassa шлёт HTTP-уведомления.
 * Источник истины: https://yookassa.ru/developers/using-api/webhooks
 *
 * ЮKassa не подписывает webhook'и — единственная защита endpoint'а
 * это IP-фильтр на стороне приложения. Список зафиксирован документацией
 * и меняется крайне редко (раз в несколько лет). Поэтому держим const,
 * а не в env: env-переменная, выставленная неправильно, тихо откроет
 * webhook миру; const в коде ловится code review.
 *
 * IPv4 и IPv6 CIDR-блоки. Реализация поддерживает оба.
 */
final class YooKassaIpAllowlist
{
    /**
     * @var list<string> CIDR-блоки или одиночные IP.
     */
    public const IPS = [
        // IPv4 (см. документацию выше)
        '185.71.76.0/27',
        '185.71.77.0/27',
        '77.75.153.0/25',
        '77.75.156.11',
        '77.75.156.35',
        '77.75.154.128/25',
        '2a02:5180::/32',
    ];

    public function isAllowed(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }
        foreach (self::IPS as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        // Одиночный IP без префикса.
        if (!str_contains($range, '/')) {
            return inet_pton($ip) === inet_pton($range);
        }
        [$subnet, $maskBitsRaw] = explode('/', $range, 2);
        $maskBits = (int) $maskBitsRaw;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $totalBits = strlen($ipBin) * 8;
        if ($maskBits < 0 || $maskBits > $totalBits) {
            return false;
        }

        // Строим бинарную маску $maskBits единиц, остаток нули.
        $fullBytes = intdiv($maskBits, 8);
        $remainder = $maskBits % 8;
        $mask = str_repeat("\xff", $fullBytes);
        if ($remainder > 0) {
            $mask .= chr(0xff << (8 - $remainder) & 0xff);
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }
}
