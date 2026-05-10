<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Service\Subscription\SubscriptionService;
use App\Service\TelegramUserResolver;
use App\Telegram\UI\WelcomeMessageBuilder;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * /start — точка входа в бота. Бизнес-логика:
 *  1. Find-or-create User (через resolver).
 *  2. Если не админ и нет подписки — стартуем 7-дневный триал.
 *  3. В зависимости от «триал стартанул сейчас?» показываем разное
 *     приветствие — текст в WelcomeMessageBuilder.
 *
 * Идемпотентность: повторный /start второй триал не выдаёт (защита от
 * абуза в SubscriptionService::startTrial), поэтому WelcomeMessageBuilder
 * увидит null и покажет стандартное приветствие без 🎁.
 */
class StartHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly SubscriptionService $subscriptions,
        private readonly WelcomeMessageBuilder $welcome,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        if ($user->isAdmin()) {
            $bot->sendMessage(text: $this->welcome->buildForAdmin($user));

            return;
        }

        $trialJustStarted = false;
        if ($this->subscriptions->getActiveSubscription($user) === null) {
            $sub = $this->subscriptions->startTrial($user, $now);
            if ($sub !== null) {
                $trialJustStarted = true;
                $this->logger->info('Trial auto-started on /start', [
                    'user_id' => $user->getId()->toRfc4122(),
                ]);
            }
        }

        $text = $trialJustStarted
            ? $this->welcome->buildWithTrial($user)
            : $this->welcome->buildStandard($user);

        $bot->sendMessage(text: $text);
    }
}
