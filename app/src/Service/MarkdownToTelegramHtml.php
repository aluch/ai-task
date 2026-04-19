<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Минимальный конвертер markdown от Claude в Telegram HTML.
 *
 * Claude любит писать **жирным**, *курсивом*, `code` — в Telegram без parse_mode
 * это отображается со звёздочками. MarkdownV2 требует эскейпа массы спецсимволов,
 * HTML проще — достаточно экранировать `<`, `>`, `&` в обычном тексте.
 *
 * Поддерживается только то, что Claude реально использует в коротких ответах:
 * `**bold**`, `*italic*`, `` `code` ``. Списки, заголовки, ссылки не конвертируем —
 * ассистент должен писать кратко и без них (см. system prompt).
 */
class MarkdownToTelegramHtml
{
    private const PLACEHOLDER_BOLD_OPEN = "\x01B_OPEN\x01";
    private const PLACEHOLDER_BOLD_CLOSE = "\x01B_CLOSE\x01";
    private const PLACEHOLDER_ITALIC_OPEN = "\x01I_OPEN\x01";
    private const PLACEHOLDER_ITALIC_CLOSE = "\x01I_CLOSE\x01";
    private const PLACEHOLDER_CODE_OPEN = "\x01C_OPEN\x01";
    private const PLACEHOLDER_CODE_CLOSE = "\x01C_CLOSE\x01";

    public function convert(string $markdown): string
    {
        // 1. Размечаем форматирование плейсхолдерами (unicode-safe, используем `u` flag)
        // Порядок важен: сначала `code` (чтобы `**` и `*` внутри бэктиков не ломались),
        // потом ** (чтобы * не захватывал одиночные звёздочки внутри **).
        $text = $markdown;
        $text = preg_replace('/`([^`\n]+?)`/u', self::PLACEHOLDER_CODE_OPEN . '$1' . self::PLACEHOLDER_CODE_CLOSE, $text) ?? $text;
        $text = preg_replace('/\*\*([^*\n]+?)\*\*/u', self::PLACEHOLDER_BOLD_OPEN . '$1' . self::PLACEHOLDER_BOLD_CLOSE, $text) ?? $text;
        $text = preg_replace('/\*([^*\n]+?)\*/u', self::PLACEHOLDER_ITALIC_OPEN . '$1' . self::PLACEHOLDER_ITALIC_CLOSE, $text) ?? $text;

        // 2. Эскейпим HTML-чары во всём получившемся тексте (и внутри разметки тоже —
        // там мог быть `<`, `&amp;` и т.п., которые Telegram съест).
        $text = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');

        // 3. Возвращаем плейсхолдеры обратно в HTML-теги.
        return strtr($text, [
            self::PLACEHOLDER_BOLD_OPEN => '<b>',
            self::PLACEHOLDER_BOLD_CLOSE => '</b>',
            self::PLACEHOLDER_ITALIC_OPEN => '<i>',
            self::PLACEHOLDER_ITALIC_CLOSE => '</i>',
            self::PLACEHOLDER_CODE_OPEN => '<code>',
            self::PLACEHOLDER_CODE_CLOSE => '</code>',
        ]);
    }
}
