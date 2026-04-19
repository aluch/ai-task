<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Entity\Task;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Хелпер для сборки клавиатур inline-пагинации. Не содержит бизнес-логики —
 * только компоновка кнопок.
 *
 * Callback_data структура:
 *
 *   SELECT TASK (формирует caller, Paginator не трогает):
 *     done:<uuid>, snz:s1:<uuid>, deps:<uuid>, dep:s1:<uuid>, dep:u1:<uuid>
 *
 *   MENU CONTROLS (формирует Paginator по menuPrefix):
 *     <menuPrefix>:p:<sessionKey>:<page>     — переключить страницу
 *     <menuPrefix>:search:<sessionKey>        — нажать «Поиск»
 *     <menuPrefix>:close:<sessionKey>         — закрыть список
 *     <menuPrefix>:noop:<sessionKey>          — клик по индикатору страницы
 *
 * menuPrefix для каждого handler'а свой, причём НЕ пересекается с select-префиксом:
 *   /done         → menuPrefix='done:m'
 *   /snooze (s1)  → menuPrefix='snz:m'
 *   /deps         → menuPrefix='deps:m'
 *   /block (s1)   → menuPrefix='dep:s1:m'
 *   /unblock (u1) → menuPrefix='dep:u1:m'
 *   /list         → menuPrefix='list'  (select'а нет, поэтому двоеточие не нужно)
 *
 * UUID = 36 символов hex+дефисы, никогда не начинается с `p`/`m`/`search`/`close`/`noop`,
 * так что done:<uuid> и done:m:p... не пересекаются в роутере.
 */
class Paginator
{
    /**
     * @param Task[] $tasks задачи текущей страницы
     * @param callable(Task): string $labelBuilder текст кнопки
     * @param callable(Task): string $selectCallbackBuilder callback_data кнопки
     */
    public function buildTaskPickerKeyboard(
        array $tasks,
        callable $labelBuilder,
        callable $selectCallbackBuilder,
        string $menuPrefix,
        string $sessionKey,
        int $currentPage,
        int $totalPages,
        bool $withSearch = true,
    ): InlineKeyboardMarkup {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($tasks as $task) {
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $labelBuilder($task),
                    callback_data: $selectCallbackBuilder($task),
                ),
            );
        }

        $this->appendNavAndControls($keyboard, $menuPrefix, $sessionKey, $currentPage, $totalPages, $withSearch);

        return $keyboard;
    }

    /**
     * Для /list — только навигация + закрыть, без select-кнопок на задачи.
     */
    public function buildListKeyboard(
        string $sessionKey,
        int $currentPage,
        int $totalPages,
    ): InlineKeyboardMarkup {
        $keyboard = InlineKeyboardMarkup::make();
        $this->appendNavAndControls($keyboard, 'list', $sessionKey, $currentPage, $totalPages, withSearch: false);

        return $keyboard;
    }

    private function appendNavAndControls(
        InlineKeyboardMarkup $keyboard,
        string $menuPrefix,
        string $sessionKey,
        int $currentPage,
        int $totalPages,
        bool $withSearch,
    ): void {
        if ($totalPages > 1) {
            $navRow = [];
            if ($currentPage > 1) {
                $navRow[] = InlineKeyboardButton::make(
                    text: '← Назад',
                    callback_data: "{$menuPrefix}:p:{$sessionKey}:" . ($currentPage - 1),
                );
            }
            $navRow[] = InlineKeyboardButton::make(
                text: "Стр. {$currentPage}/{$totalPages}",
                callback_data: "{$menuPrefix}:noop:{$sessionKey}",
            );
            if ($currentPage < $totalPages) {
                $navRow[] = InlineKeyboardButton::make(
                    text: 'Далее →',
                    callback_data: "{$menuPrefix}:p:{$sessionKey}:" . ($currentPage + 1),
                );
            }
            $keyboard->addRow(...$navRow);
        }

        if ($withSearch) {
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: '🔍 Поиск по названию',
                    callback_data: "{$menuPrefix}:search:{$sessionKey}",
                ),
            );
        }

        $keyboard->addRow(
            InlineKeyboardButton::make(
                text: '✖ Закрыть',
                callback_data: "{$menuPrefix}:close:{$sessionKey}",
            ),
        );
    }
}
