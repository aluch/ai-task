<?php

declare(strict_types=1);

namespace App\Smoke;

use App\Clock\Clock;
use App\Clock\FrozenClock;
use App\Entity\Task;
use App\Entity\User;
use App\MessageHandler\CheckDeadlineRemindersHandler;
use App\MessageHandler\CheckPeriodicRemindersHandler;
use App\MessageHandler\CheckSnoozeWakeupsHandler;
use App\Notification\InMemoryTelegramNotifier;
use App\Notification\ReminderSender;
use App\Notification\TelegramNotifier;
use App\Service\UserActivityTracker;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Общая инфраструктура для smoke-команд: тестовый пользователь, подмена
 * TelegramNotifier на in-memory, подмена Clock на FrozenClock (если
 * запрошено), reset БД для этого юзера.
 *
 * Юзер идентифицируется telegram_id=999999999 (заведомо не пересекается
 * с реальными аккаунтами). Все задачи с этим user_id — зона ответственности
 * smoke-команд.
 */
final class SmokeHarness
{
    public const TEST_TELEGRAM_ID = '999999999';

    private InMemoryTelegramNotifier $notifier;
    private ?FrozenClock $frozenClock = null;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TelegramNotifier $telegramNotifier,
        private readonly ReminderSender $reminderSender,
        private readonly CheckDeadlineRemindersHandler $deadlineHandler,
        private readonly CheckPeriodicRemindersHandler $periodicHandler,
        private readonly CheckSnoozeWakeupsHandler $snoozeHandler,
        private readonly UserActivityTracker $activityTracker,
    ) {
        $this->notifier = new InMemoryTelegramNotifier();
        $this->telegramNotifier->useInMemory($this->notifier);
    }

    public function notifier(): InMemoryTelegramNotifier
    {
        return $this->notifier;
    }

    /**
     * Установить «текущее время» на заданный момент. Влияет на все сервисы
     * reminder pipeline: в их свойство $clock через reflection подменяется
     * общий FrozenClock. Последующие `advance()` / `setTo()` на возвращённом
     * FrozenClock мгновенно видны этим сервисам (они держат ту же ссылку).
     *
     * Сервисы, НЕ инжектящие Clock, продолжают видеть реальное `now` —
     * терпимо для scheduled-сценариев, где вся логика времени внутри
     * reminder pipeline.
     */
    public function freezeTimeAt(\DateTimeImmutable $moment): FrozenClock
    {
        $utc = $moment->setTimezone(new \DateTimeZone('UTC'));
        if ($this->frozenClock === null) {
            $this->frozenClock = new FrozenClock($utc);
            $this->swapClockIn([
                $this->reminderSender,
                $this->deadlineHandler,
                $this->periodicHandler,
                $this->snoozeHandler,
                $this->activityTracker,
            ]);
        } else {
            $this->frozenClock->setTo($utc);
        }

        return $this->frozenClock;
    }

    /**
     * Reflection-подмена свойства `clock` (типа Clock) на наш FrozenClock
     * во всех переданных сервисах. Работает для readonly-свойств в PHP 8.3
     * через ReflectionProperty (разрешено из того же класса, но для
     * внешних модификаций нужен `setValue` на экземпляре).
     */
    private function swapClockIn(array $services): void
    {
        foreach ($services as $svc) {
            $refl = new \ReflectionClass($svc);
            foreach ($refl->getProperties() as $prop) {
                $type = $prop->getType();
                if ($type instanceof \ReflectionNamedType && $type->getName() === Clock::class) {
                    $prop->setValue($svc, $this->frozenClock);
                }
            }
        }
    }

    public function clock(): ?FrozenClock
    {
        return $this->frozenClock;
    }

    /**
     * Удалить тестового пользователя и все его задачи. Возвращает количество
     * удалённых задач (юзер сам — 0 или 1).
     *
     * @return array{tasks: int, user: int}
     */
    public function reset(): array
    {
        $em = $this->doctrine->getManager();
        $user = $this->findTestUser();
        if ($user === null) {
            return ['tasks' => 0, 'user' => 0];
        }

        // Считаем задачи ДО удаления (cascade снесёт их вместе с юзером,
        // но нам хочется отчитаться по количеству).
        $taskCount = (int) $em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Task::class, 't')
            ->andWhere('t.user = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getSingleScalarResult();

        // Dissolve задач через task_dependencies/task_context_link — cascade
        // в схеме должен срабатывать при удалении Task, но явный SQL-снос
        // надёжнее (на случай orphanRemoval-квирков).
        $em->createQuery('DELETE FROM App\Entity\Task t WHERE t.user = :u')
            ->setParameter('u', $user)
            ->execute();

        $em->remove($user);
        $em->flush();

        // Сбрасываем identity map, чтобы следующий ensureTestUser работал
        // с чистой «новый инстанс из БД»-семантикой. Без этого Doctrine
        // ругается «new entity found through relationship» при persist
        // нового Task в следующем сценарии — старый detached User
        // остаётся висеть в UoW.
        $em->clear();

        return ['tasks' => $taskCount, 'user' => 1];
    }

    /**
     * Find-or-create тестовый User, зафиксировать `lastMessageAt = null`
     * (чтобы фильтр recently_active не стрелял по умолчанию).
     */
    public function ensureTestUser(): User
    {
        $em = $this->doctrine->getManager();
        $user = $this->findTestUser();
        if ($user === null) {
            $user = new User();
            $user->setTelegramId(self::TEST_TELEGRAM_ID);
            $user->setName('Smoke Test User');
            $user->setTimezone('Europe/Tallinn');
            $em->persist($user);
            $em->flush();
        }

        // Сбросить lastMessageAt чтобы isRecentlyActive=false по умолчанию.
        $user->setLastMessageAt(null);
        $em->flush();

        return $user;
    }

    public function findTestUser(): ?User
    {
        $em = $this->doctrine->getManager();

        return $em->getRepository(User::class)
            ->findOneBy(['telegramId' => self::TEST_TELEGRAM_ID]);
    }

    /**
     * Вернуть актуальное состояние задачи из identity map EM. После flush'ей
     * сущность в identity map уже содержит актуальные поля — ни `em->clear()`
     * (убивает User в identity map → cascade-persist-ошибка при следующем
     * persist Task), ни `em->refresh()` (detach'ит связанного User) здесь
     * не нужны.
     */
    public function refreshTask(Uuid $id): ?Task
    {
        $em = $this->doctrine->getManager();

        return $em->getRepository(Task::class)->find($id);
    }
}
