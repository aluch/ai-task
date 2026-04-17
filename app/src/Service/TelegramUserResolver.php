<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use SergiX44\Nutgram\Nutgram;

/**
 * Find-or-create User по telegram_id.
 *
 * Использует ManagerRegistry (не EntityManagerInterface): в долгоживущем
 * bot-процессе после resetManager() прямая ссылка на EM становится stale.
 * getManager() всегда отдаёт живой EM. См. правило в CLAUDE.md.
 */
class TelegramUserResolver
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function resolve(Nutgram $bot): User
    {
        $em = $this->doctrine->getManager();
        $users = $em->getRepository(User::class);

        $telegramId = (string) $bot->userId();
        $user = $users->findOneBy(['telegramId' => $telegramId]);

        if ($user !== null) {
            $this->updateNameIfEmpty($user, $bot);

            return $user;
        }

        $user = new User();
        $user->setTelegramId($telegramId);
        $user->setName($this->extractName($bot));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function updateNameIfEmpty(User $user, Nutgram $bot): void
    {
        if ($user->getName() !== null) {
            return;
        }

        $name = $this->extractName($bot);
        if ($name === null) {
            return;
        }

        $user->setName($name);
        $this->doctrine->getManager()->flush();
    }

    private function extractName(Nutgram $bot): ?string
    {
        $from = $bot->message()?->from;
        if ($from === null) {
            return null;
        }

        $parts = array_filter([$from->first_name, $from->last_name]);

        return $parts !== [] ? implode(' ', $parts) : null;
    }
}
