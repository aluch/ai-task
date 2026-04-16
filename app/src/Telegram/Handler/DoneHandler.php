<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Repository\TaskRepository;
use App\Service\TelegramUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;

class DoneHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly TaskRepository $tasks,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Nutgram $bot, ?string $args = null): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = $bot->message()?->text ?? '';

        $shortId = trim(substr($text, 6)); // strip "/done "
        if ($shortId === '') {
            $bot->sendMessage(text: "Использование: /done <id>\nID — первые 8 символов UUID из /list.");

            return;
        }

        $matches = $this->findByShortId($shortId, $user);

        if ($matches === []) {
            $bot->sendMessage(text: "Задача с ID {$shortId}… не найдена среди твоих задач.");

            return;
        }

        if (count($matches) > 1) {
            $lines = ["Найдено несколько задач по ID {$shortId}…:"];
            foreach ($matches as $t) {
                $lines[] = '  ' . substr($t->getId()->toRfc4122(), 0, 13) . '… — ' . $t->getTitle();
            }
            $lines[] = '';
            $lines[] = 'Уточни ID (больше символов).';
            $bot->sendMessage(text: implode("\n", $lines));

            return;
        }

        $task = $matches[0];

        // Запомним задачи, которые были заблокированы до выполнения
        $wasBlockingBefore = [];
        foreach ($task->getBlockedTasks() as $downstream) {
            if ($downstream->isBlocked()) {
                $wasBlockingBefore[$downstream->getId()->toRfc4122()] = $downstream;
            }
        }

        $task->markDone();
        $this->em->flush();

        $lines = ["✅ Задача выполнена: {$task->getTitle()}"];

        // Проверяем какие задачи разблокировались
        $unblocked = [];
        foreach ($wasBlockingBefore as $id => $downstream) {
            if (!$downstream->isBlocked()) {
                $unblocked[] = $downstream;
            }
        }

        if ($unblocked !== []) {
            $lines[] = '';
            $lines[] = '🔓 Разблокирована:';
            foreach ($unblocked as $t) {
                $sid = substr($t->getId()->toRfc4122(), 0, 8);
                $lines[] = "  • {$t->getTitle()} — {$sid}";
            }
        }

        $bot->sendMessage(text: implode("\n", $lines));
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
