<?php

declare(strict_types=1);

namespace App\Service;

class RelativeTimeParser
{
    private const SHORTHAND_UNITS = [
        's' => 'seconds',
        'm' => 'minutes',
        'h' => 'hours',
        'd' => 'days',
        'w' => 'weeks',
    ];

    /**
     * Парсит строку времени — относительную (+2h, -30m, +1d) или абсолютную
     * (2026-04-20 18:00, tomorrow 09:00). Возвращает результат в UTC.
     *
     * Короткие формы +2h/-30m раскрываются в +2 hours/-30 minutes, потому что
     * PHP парсит "+2h" как фиксированный TZ-офсет +02:00, а не "через 2 часа".
     * По той же причине "m" = minutes (для месяцев: "+1 month").
     */
    public function parse(string $raw, \DateTimeZone $userTz): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            if ($raw[0] === '+' || $raw[0] === '-') {
                $expanded = $this->expandShorthand($raw);
                $now = new \DateTimeImmutable('now', $utc);
                $modified = $now->modify($expanded);
                if ($modified === false || $modified->getTimestamp() === $now->getTimestamp()) {
                    return null;
                }

                return $modified;
            }

            return (new \DateTimeImmutable($raw, $userTz))->setTimezone($utc);
        } catch (\Exception) {
            return null;
        }
    }

    private function expandShorthand(string $raw): string
    {
        if (preg_match('/^([+-])(\d+)\s*([a-z])$/i', $raw, $m) === 1) {
            $unit = self::SHORTHAND_UNITS[strtolower($m[3])] ?? null;
            if ($unit !== null) {
                return $m[1] . $m[2] . ' ' . $unit;
            }
        }

        return $raw;
    }

    /**
     * Парсит длительность в минутах. Поддерживает латиницу (m, h) и кириллицу
     * (м, ч), дробные часы (1.5h → 90), чистое число = минуты.
     *
     * @throws \InvalidArgumentException при некорректном формате
     */
    public function parseToMinutes(string $input): int
    {
        $raw = trim($input);
        if ($raw === '') {
            throw new \InvalidArgumentException('Empty duration.');
        }

        // Нормализуем: нижний регистр, запятая → точка для дробных, убираем пробелы
        $normalized = str_replace([',', ' '], ['.', ''], mb_strtolower($raw));

        // Паттерн: число + опциональная единица (m/h/м/ч)
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(m|h|м|ч)?$/u', $normalized, $m) !== 1) {
            throw new \InvalidArgumentException(sprintf('Cannot parse duration: "%s".', $input));
        }

        $value = (float) $m[1];
        $unit = $m[2] ?? '';

        $minutes = match ($unit) {
            'h', 'ч' => (int) round($value * 60),
            'm', 'м', '' => (int) round($value),
        };

        if ($minutes <= 0) {
            throw new \InvalidArgumentException(sprintf('Duration must be positive: "%s".', $input));
        }

        return $minutes;
    }
}
