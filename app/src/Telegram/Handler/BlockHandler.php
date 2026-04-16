<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Enum\TaskStatus;
use App\Exception\CyclicDependencyException;
use App\Repository\TaskRepository;
use App\Service\DependencyValidator;
use App\Service\TelegramUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class BlockHandler
{
    private const MAX_BUTTONS = 8;

    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly DependencyValidator $depValidator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $cmdArgs = trim(substr($text, 7)); // strip "/block "
        $parts = preg_split('/\s+/', $cmdArgs, 2);

        if ($cmdArgs === '' || count($parts) < 2) {
            if ($cmdArgs !== '' && count($parts) === 1) {
                $bot->sendMessage(text: "Использование: /block <task_id> <blocker_id>\nИли /block без аргументов для интерактивного выбора.");

                return;
            }

            $this->startInteractiveFlow($bot, $user);

            return;
        }

        [$blockedShort, $blockerShort] = $parts;
        $this->createLink($bot, $user, $blockedShort, $blockerShort);
    }

    public function createLink(
        Nutgram $bot,
        \App\Entity\User $user,
        string $blockedShort,
        string $blockerShort,
        bool $editMessage = false,
    ): void {
        $blockedMatches = $this->findByShortId($blockedShort, $user);
        $blockerMatches = $this->findByShortId($blockerShort, $user);

        if ($blockedMatches === []) {
            $this->reply($bot, "Задача {$blockedShort}… не найдена.", $editMessage);

            return;
        }

        if ($blockerMatches === []) {
            $this->reply($bot, "Задача {$blockerShort}… не найдена.", $editMessage);

            return;
        }

        if (count($blockedMatches) > 1 || count($blockerMatches) > 1) {
            $this->reply($bot, 'Один из ID неоднозначен, уточни (больше символов).', $editMessage);

            return;
        }

        $blocked = $blockedMatches[0];
        $blocker = $blockerMatches[0];

        if ($blocked->getId()->equals($blocker->getId())) {
            $this->reply($bot, '❌ Задача не может блокировать саму себя.', $editMessage);

            return;
        }

        if ($blocked->getBlockedBy()->contains($blocker)) {
            $this->reply($bot, '🔗 Связь уже существует.', $editMessage);

            return;
        }

        try {
            $this->depValidator->validateNoCycle($blocked, $blocker);
        } catch (CyclicDependencyException $e) {
            $this->reply($bot, '❌ ' . $e->getMessage(), $editMessage);

            return;
        }

        $blocked->addBlocker($blocker);
        $this->em->flush();

        $this->reply($bot, implode("\n", [
            '🔗 Связь создана',
            "⛔ {$blocked->getTitle()}",
            '⬅️ заблокирована',
            "✅ {$blocker->getTitle()}",
        ]), $editMessage);
    }

    private function startInteractiveFlow(Nutgram $bot, \App\Entity\User $user): void
    {
        $tasks = $this->tasks->findForUser($user, limit: self::MAX_BUTTONS + 1);

        if ($tasks === []) {
            $bot->sendMessage(text: 'Нет открытых задач.');

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $shown = 0;
        foreach ($tasks as $task) {
            if ($shown >= self::MAX_BUTTONS) {
                break;
            }
            $shortId = substr($task->getId()->toRfc4122(), 0, 8);
            $label = mb_substr($task->getTitle(), 0, 30);
            $keyboard->addRow(
                InlineKeyboardButton::make(text: $label, callback_data: "dep:s1:{$shortId}"),
            );
            $shown++;
        }

        $bot->sendMessage(
            text: 'Какую задачу нужно заблокировать?',
            reply_markup: $keyboard,
        );
    }

    private function reply(Nutgram $bot, string $text, bool $edit): void
    {
        if ($edit) {
            $bot->editMessageText(text: $text, reply_markup: null);
        } else {
            $bot->sendMessage(text: $text);
        }
    }

    /**
     * @return Task[]
     */
    private function findByShortId(string $prefix, \App\Entity\User $user): array
    {
        $all = $this->tasks->findBy(['user' => $user]);

        return array_values(array_filter(
            $all,
            fn (Task $t) => str_starts_with($t->getId()->toRfc4122(), $prefix),
        ));
    }
}
