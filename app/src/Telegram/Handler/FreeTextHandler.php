<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Service\TelegramUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;

class FreeTextHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        $text = trim($bot->message()?->text ?? '');

        if ($text === '') {
            return;
        }

        $title = mb_substr($text, 0, 255);

        $task = new Task($user, $title);
        $this->em->persist($task);
        $this->em->flush();

        $shortId = substr($task->getId()->toRfc4122(), 0, 8);
        $bot->sendMessage(
            text: <<<MSG
            ✅ Задача создана: {$title}
            ID: {$shortId}

            Пока без дедлайна и контекстов — следующая версия научится распознавать их из текста.
            MSG,
        );
    }
}
