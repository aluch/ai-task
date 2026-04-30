<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Решает: пускать пользователя дальше middleware или показать
 * страничку «доступ ограничен». Также — админ ли это (отдельная
 * роль, всегда allowed автоматически).
 *
 * Whitelist хранится в users.is_allowed (миграция Version20260428000000).
 * Админ — единственный telegram_id из env ADMIN_TELEGRAM_ID. На старте
 * проекта этого хватает; если будет нужно — превратим в bool-колонку
 * users.is_admin.
 */
class AccessGate
{
    /** Сколько дней блокировка после rejected: повторно запрос не принимается. */
    public const REJECT_COOLDOWN_DAYS = 30;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly string $adminTelegramId,
    ) {
    }

    public function isAdmin(User $user): bool
    {
        if ($this->adminTelegramId === '') {
            return false;
        }

        return (string) $user->getTelegramId() === $this->adminTelegramId;
    }

    /**
     * Главный gate: пускаем дальше или нет. Админ всегда пропускается.
     * Для незваных автоматически даём доступ если они админ — без
     * необходимости явно создавать запись.
     */
    public function isAllowed(User $user): bool
    {
        if ($this->isAdmin($user)) {
            // Админу — auto-allow на лету, чтобы не зависеть от бутстрап-миграции.
            if (!$user->isAllowed()) {
                $user->setAllowed(true);
                $this->doctrine->getManager()->flush();
            }

            return true;
        }

        return $user->isAllowed();
    }

    /**
     * Можно ли пользователю запросить доступ. Нельзя если уже отклонили
     * меньше REJECT_COOLDOWN_DAYS дней назад — иначе можно DoS-ить
     * админа повторными запросами.
     */
    public function canRequestAccess(User $user, \DateTimeImmutable $now): bool
    {
        $rejected = $user->getRequestRejectedAt();
        if ($rejected === null) {
            return true;
        }
        $diffDays = ($now->getTimestamp() - $rejected->getTimestamp()) / 86400;

        return $diffDays >= self::REJECT_COOLDOWN_DAYS;
    }

    public function adminTelegramId(): string
    {
        return $this->adminTelegramId;
    }
}
