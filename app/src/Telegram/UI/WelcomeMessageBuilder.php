<?php

declare(strict_types=1);

namespace App\Telegram\UI;

use App\Entity\User;
use App\Service\PlanCatalog;

/**
 * Тексты приветствия /start. Изолированы тут, чтобы переводы/правки
 * текста не трогали handler, и smoke-сценарии могли проверять контент
 * без поднятия Nutgram.
 *
 * Три варианта:
 *  - admin → стандартное приветствие без подписочной риторики (админ
 *    безлимитен, ему не нужно знать про планы).
 *  - первый /start с триалом (только что стартанул) → показываем
 *    «🎁 7 дней Pro».
 *  - повторный /start или триал был раньше → стандартное приветствие.
 */
final class WelcomeMessageBuilder
{
    public function __construct(
        private readonly PlanCatalog $catalog,
    ) {
    }

    public function buildForAdmin(User $user): string
    {
        return $this->buildStandard($user);
    }

    public function buildWithTrial(User $user): string
    {
        $name = $user->getName() ?? 'друг';
        $days = $this->catalog->trialDays();
        $freeLimit = $this->catalog->actionLimit(\App\Domain\Subscription\Plan::Free);

        return <<<MSG
            Привет, {$name}! 👋

            Я Помни — твой помощник по делам.

            🎁 У тебя {$days} дней безлимитного Pro — пробуй всё!
            Через неделю — переход на Free ({$freeLimit} действий/мес) или оформи Pro.

            Просто пиши задачи как другу:
            • «Купить хлеб через час»
            • «Завтра в 15:00 встреча, предупреди заранее»
            • «Что у меня на сегодня?»

            Все команды — /help.
            MSG;
    }

    public function buildStandard(User $user): string
    {
        $name = $user->getName() ?? 'друг';

        return <<<MSG
            Привет, {$name}! 👋

            Я Помни — твой помощник по делам.

            Просто пиши свободным текстом, как другу:
            • «Купить хлеб через час»
            • «Завтра в 15:00 встреча, предупреди заранее»
            • «Что у меня на сегодня?»
            • «Свободен 2 часа, что взять?»

            Я разберусь сам — создам задачу, напомню вовремя, подберу что сделать.

            Все команды — /help.
            MSG;
    }
}
