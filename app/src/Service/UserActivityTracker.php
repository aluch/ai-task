<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Отмечает что пользователь только что взаимодействовал с ботом.
 * Сохраняется в User.lastMessageAt (UTC). Используется Scheduler'ом
 * чтобы не слать напоминание сразу во время активного диалога —
 * см. User::isRecentlyActive().
 */
class UserActivityTracker
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private Clock $clock,
    ) {
    }

    public function recordMessage(User $user): void
    {
        $em = $this->doctrine->getManager();
        if (!$em->contains($user)) {
            $user = $em->find(User::class, $user->getId());
            if ($user === null) {
                return;
            }
        }

        $user->setLastMessageAt($this->clock->now());
        $em->flush();
    }
}
