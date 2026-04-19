<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\AI\Assistant;
use App\Service\TelegramUserResolver;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

class AssistantHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly Assistant $assistant,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = trim($bot->message()?->text ?? '');

        if ($text === '') {
            return;
        }

        $thinking = $bot->sendMessage(text: '🤔 Думаю...');
        $chatId = $bot->chatId();
        $messageId = $thinking?->message_id;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            $result = $this->assistant->handle($user, $text, $now);

            $this->logger->info('Assistant result', [
                'user_id' => $user->getId()->toRfc4122(),
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'iterations' => $result->iterations,
                'tools_called' => $result->toolsCalled,
            ]);

            $this->editOrSend($bot, $chatId, $messageId, $result->replyText);
        } catch (\Throwable $e) {
            $this->logger->error('Assistant failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $this->editOrSend(
                $bot,
                $chatId,
                $messageId,
                '⚠️ Что-то пошло не так, попробуй ещё раз.',
            );
        }
    }

    private function editOrSend(Nutgram $bot, int $chatId, ?int $messageId, string $text): void
    {
        if ($messageId !== null) {
            $bot->editMessageText(text: $text, chat_id: $chatId, message_id: $messageId);
        } else {
            $bot->sendMessage(text: $text);
        }
    }
}
