<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use SergiX44\Nutgram\Nutgram;

class TelegramUserResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    public function resolve(Nutgram $bot): User
    {
        $telegramId = (string) $bot->userId();

        $user = $this->users->findByTelegramId($telegramId);

        if ($user !== null) {
            $this->updateNameIfEmpty($user, $bot);

            return $user;
        }

        $user = new User();
        $user->setTelegramId($telegramId);
        $user->setName($this->extractName($bot));

        $this->em->persist($user);
        $this->em->flush();

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
        $this->em->flush();
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
