<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Domain\Subscription\SubscriptionStatus;
use App\Entity\Subscription;
use App\Entity\Task;
use App\Entity\User;
use App\Service\AccessGate;
use App\Service\Subscription\SubscriptionService;
use App\Service\Subscription\SubscriptionStatsService;
use App\Service\TelegramUserResolver;
use App\Enum\TaskStatus;
use App\Telegram\UI\AdminStatsMessageBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * /admin <subcommand> [args] — команды для админа (ID из ADMIN_TELEGRAM_ID).
 *
 * Доступ:
 *   /admin requests       — pending-запросы доступа с кнопками approve/reject
 *   /admin users          — все allowed users + count активных задач
 *   /admin invite <tg_id> — выдать доступ без запроса
 *   /admin revoke <tg_id> — забрать доступ
 *
 * Подписки (S3):
 *   /admin grant_trial <tg_id>      — выдать 7-дневный триал
 *   /admin grant_pro <tg_id> <days> — выдать Pro на N дней без оплаты
 *   /admin revoke_subscription <tg_id> — мгновенно погасить подписку
 *
 * Аналитика (S3):
 *   /admin stats — текущие числа по подпискам, MRR, активность за 7 дней
 *
 * Не-админу — «Команда не найдена» (как любая неизвестная). Не выдаём
 * существование /admin наружу.
 */
class AdminHandler
{
    public function __construct(
        private readonly TelegramUserResolver $userResolver,
        private readonly AccessGate $gate,
        private readonly ManagerRegistry $doctrine,
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionStatsService $stats,
        private readonly AdminStatsMessageBuilder $statsBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $user = $this->userResolver->resolve($bot);
        if (!$this->gate->isAdmin($user)) {
            // Не палим существование /admin — отвечаем как на неизвестную команду.
            $bot->sendMessage(text: 'Неизвестная команда. Напиши /help для списка команд.');

            return;
        }

        $text = trim((string) ($bot->message()?->text ?? ''));
        // /admin или /admin <subcmd> [args]
        $args = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        array_shift($args); // убрать /admin
        $sub = (string) ($args[0] ?? '');

        match ($sub) {
            'requests' => $this->cmdRequests($bot),
            'users' => $this->cmdUsers($bot),
            'invite' => $this->cmdInvite($bot, (string) ($args[1] ?? '')),
            'revoke' => $this->cmdRevoke($bot, (string) ($args[1] ?? '')),
            'grant_trial' => $this->cmdGrantTrial($bot, (string) ($args[1] ?? '')),
            'grant_pro' => $this->cmdGrantPro($bot, (string) ($args[1] ?? ''), (string) ($args[2] ?? '')),
            'revoke_subscription' => $this->cmdRevokeSubscription($bot, (string) ($args[1] ?? '')),
            'stats' => $this->cmdStats($bot),
            default => $this->cmdHelp($bot),
        };
    }

    private function cmdHelp(Nutgram $bot): void
    {
        $bot->sendMessage(text: <<<'MSG'
        🛠 Админ-команды

        Доступ:
        /admin requests       — pending запросы доступа
        /admin users          — все allowed users
        /admin invite <tg_id> — выдать доступ
        /admin revoke <tg_id> — забрать доступ

        Подписки:
        /admin grant_trial <tg_id>      — выдать триал на 7 дней
        /admin grant_pro <tg_id> <days> — выдать Pro на N дней
        /admin revoke_subscription <tg_id>  — отключить Pro

        Аналитика:
        /admin stats — статистика подписок
        MSG);
    }

    private function cmdRequests(Nutgram $bot): void
    {
        $em = $this->doctrine->getManager();
        $pending = $em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->andWhere('u.isAllowed = false')
            ->andWhere('u.accessRequestedAt IS NOT NULL')
            ->orderBy('u.accessRequestedAt', 'ASC')
            ->getQuery()
            ->getResult();

        if ($pending === []) {
            $bot->sendMessage(text: 'Нет pending-запросов.');

            return;
        }

        foreach ($pending as $u) {
            assert($u instanceof User);
            $name = $u->getName() ?? '?';
            $tgId = $u->getTelegramId() ?? '?';
            $when = $u->getAccessRequestedAt()?->format('Y-m-d H:i') ?? '?';
            $userUuid = $u->getId()->toRfc4122();

            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(text: '✅ Разрешить', callback_data: "access:approve:{$userUuid}"),
                InlineKeyboardButton::make(text: '❌ Отклонить', callback_data: "access:reject:{$userUuid}"),
            );

            $bot->sendMessage(
                text: "👤 {$name}\n🆔 {$tgId}\n🕒 {$when} UTC",
                reply_markup: $keyboard,
            );
        }
    }

    private function cmdUsers(Nutgram $bot): void
    {
        $em = $this->doctrine->getManager();
        $users = $em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->andWhere('u.isAllowed = true')
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        if ($users === []) {
            $bot->sendMessage(text: 'Allowed users: 0.');

            return;
        }

        $taskRepo = $em->getRepository(Task::class);
        $lines = ['Allowed users (' . count($users) . '):'];
        foreach ($users as $u) {
            assert($u instanceof User);
            $active = (int) $taskRepo->createQueryBuilder('t')
                ->select('COUNT(t.id)')
                ->andWhere('t.user = :u')
                ->andWhere('t.status IN (:s)')
                ->setParameter('u', $u)
                ->setParameter('s', [TaskStatus::PENDING, TaskStatus::IN_PROGRESS, TaskStatus::SNOOZED])
                ->getQuery()
                ->getSingleScalarResult();
            $name = $u->getName() ?? '?';
            $tgId = $u->getTelegramId() ?? '?';
            $lines[] = "• {$name} (tg:{$tgId}) — {$active} активных";
        }

        $bot->sendMessage(text: implode("\n", $lines));
    }

    private function cmdInvite(Nutgram $bot, string $tgIdRaw): void
    {
        if (!ctype_digit($tgIdRaw)) {
            $bot->sendMessage(text: 'Использование: /admin invite <telegram_id>');

            return;
        }
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository(User::class);
        $target = $repo->findOneBy(['telegramId' => $tgIdRaw]);

        if ($target === null) {
            // Создаём stub, пользователь дополнит при первом /start.
            $target = new User();
            $target->setTelegramId($tgIdRaw);
            $target->setName('Invited (' . $tgIdRaw . ')');
            $em->persist($target);
        }
        $target->setAllowed(true);
        $target->setRequestRejectedAt(null);
        $em->flush();

        $this->logger->info('Admin invite', ['target_tg' => $tgIdRaw]);
        $bot->sendMessage(text: "✅ Приглашён tg:{$tgIdRaw}");
    }

    private function cmdRevoke(Nutgram $bot, string $tgIdRaw): void
    {
        if (!ctype_digit($tgIdRaw)) {
            $bot->sendMessage(text: 'Использование: /admin revoke <telegram_id>');

            return;
        }
        $em = $this->doctrine->getManager();
        $target = $em->getRepository(User::class)->findOneBy(['telegramId' => $tgIdRaw]);
        if ($target === null) {
            $bot->sendMessage(text: 'Пользователь не найден.');

            return;
        }
        if ($this->gate->isAdmin($target)) {
            $bot->sendMessage(text: '❌ Нельзя забрать доступ у админа.');

            return;
        }
        $target->setAllowed(false);
        $em->flush();

        $this->logger->info('Admin revoke', ['target_tg' => $tgIdRaw]);
        $bot->sendMessage(text: "🔒 Доступ забран у tg:{$tgIdRaw}");
    }

    private function cmdGrantTrial(Nutgram $bot, string $tgIdRaw): void
    {
        if (!ctype_digit($tgIdRaw)) {
            $bot->sendMessage(text: 'Использование: /admin grant_trial <telegram_id>');

            return;
        }
        $target = $this->findUser($tgIdRaw);
        if ($target === null) {
            $bot->sendMessage(text: 'Пользователь не найден.');

            return;
        }

        $existing = $this->doctrine->getManager()
            ->getRepository(Subscription::class)
            ->findOneBy(['user' => $target]);

        // Подписки нет — без подтверждения сразу выдаём.
        if ($existing === null) {
            $this->performGrantTrial($bot, $target);

            return;
        }

        // Иначе показываем подтверждение.
        $name = $target->getName() ?? '?';
        $plan = $existing->getPlan()->value;
        $status = $existing->getStatus()->value;
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(text: '✅ Выдать всё равно', callback_data: "admin:grant_trial:{$tgIdRaw}:confirm"),
            InlineKeyboardButton::make(text: '❌ Отмена', callback_data: "admin:grant_trial:{$tgIdRaw}:abort"),
        );
        $bot->sendMessage(
            text: "⚠️ У {$name} уже была подписка ({$plan} — {$status}).\n\nЕсли выдашь триал заново — это обнулит историю и стартанёт новый.",
            reply_markup: $keyboard,
        );
    }

    private function cmdGrantPro(Nutgram $bot, string $tgIdRaw, string $daysRaw): void
    {
        if (!ctype_digit($tgIdRaw) || !ctype_digit($daysRaw) || (int) $daysRaw < 1) {
            $bot->sendMessage(text: 'Использование: /admin grant_pro <telegram_id> <days>');

            return;
        }
        $days = (int) $daysRaw;
        $target = $this->findUser($tgIdRaw);
        if ($target === null) {
            $bot->sendMessage(text: 'Пользователь не найден.');

            return;
        }

        $existing = $this->doctrine->getManager()
            ->getRepository(Subscription::class)
            ->findOneBy(['user' => $target]);

        if ($existing === null) {
            $this->performGrantPro($bot, $target, $days);

            return;
        }

        $name = $target->getName() ?? '?';
        $plan = $existing->getPlan()->value;
        $status = $existing->getStatus()->value;
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(text: '✅ Выдать всё равно', callback_data: "admin:grant_pro:{$tgIdRaw}:{$days}:confirm"),
            InlineKeyboardButton::make(text: '❌ Отмена', callback_data: "admin:grant_pro:{$tgIdRaw}:{$days}:abort"),
        );
        $bot->sendMessage(
            text: "⚠️ У {$name} уже была подписка ({$plan} — {$status}).\n\nЕсли выдашь Pro заново — это обнулит историю и стартанёт новый период.",
            reply_markup: $keyboard,
        );
    }

    private function cmdRevokeSubscription(Nutgram $bot, string $tgIdRaw): void
    {
        if (!ctype_digit($tgIdRaw)) {
            $bot->sendMessage(text: 'Использование: /admin revoke_subscription <telegram_id>');

            return;
        }
        $target = $this->findUser($tgIdRaw);
        if ($target === null) {
            $bot->sendMessage(text: 'Пользователь не найден.');

            return;
        }
        $active = $this->subscriptions->getActiveSubscription($target);
        if ($active === null) {
            $bot->sendMessage(text: 'У пользователя нет активной подписки.');

            return;
        }
        $name = $target->getName() ?? '?';
        $keyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(text: '✅ Отключить', callback_data: "admin:revoke_subscription:{$tgIdRaw}:confirm"),
            InlineKeyboardButton::make(text: '❌ Отмена', callback_data: "admin:revoke_subscription:{$tgIdRaw}:abort"),
        );
        $bot->sendMessage(
            text: "⚠️ Точно отключить Pro для {$name}?\nЭто переведёт его на Free прямо сейчас, без grace period.",
            reply_markup: $keyboard,
        );
    }

    private function cmdStats(Nutgram $bot): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $data = $this->stats->collect($now);
        $bot->sendMessage(text: $this->statsBuilder->build($data));
    }

    /**
     * Прямое исполнение, дёргается из callback'а после confirm И из
     * cmdGrantTrial когда подтверждение не нужно (новый юзер).
     */
    public function performGrantTrial(Nutgram $bot, User $target): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sub = $this->subscriptions->forceStartTrial($target, $now);
        $this->logger->info('Admin grant_trial', ['target_tg' => $target->getTelegramId()]);
        $name = $target->getName() ?? '?';
        $tgId = $target->getTelegramId() ?? '?';
        $bot->sendMessage(
            text: "✅ Триал на 7 дней выдан {$name} (telegram_id {$tgId}).",
        );
        // sub не используется снаружи, но возвращается логическим
        // SubscriptionService::forceStartTrial — ничего больше не делаем.
        unset($sub);
    }

    public function performGrantPro(Nutgram $bot, User $target, int $days): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $sub = $this->subscriptions->forceActivatePro($target, $days, $now);
        $this->logger->info('Admin grant_pro', ['target_tg' => $target->getTelegramId(), 'days' => $days]);

        $name = $target->getName() ?? '?';
        $tgId = $target->getTelegramId() ?? '?';
        $until = $sub->getCurrentPeriodEnd()
            ->setTimezone(new \DateTimeZone($target->getTimezone()))
            ->format('d.m.Y');
        $bot->sendMessage(
            text: "✅ Pro на {$days} дней выдан {$name} (telegram_id {$tgId}).\nДействует до: {$until}.",
        );
    }

    public function performRevokeSubscription(Nutgram $bot, User $target): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $active = $this->subscriptions->getActiveSubscription($target);
        if ($active === null) {
            $bot->sendMessage(text: 'У пользователя уже нет активной подписки.');

            return;
        }
        $this->subscriptions->expire($active, $now);
        $this->logger->info('Admin revoke_subscription', ['target_tg' => $target->getTelegramId()]);

        $name = $target->getName() ?? '?';
        $tgId = $target->getTelegramId() ?? '?';
        $bot->sendMessage(text: "✅ Подписка {$name} (tg {$tgId}) отключена. Сейчас Free.");
    }

    public function findUser(string $tgIdRaw): ?User
    {
        $em = $this->doctrine->getManager();

        return $em->getRepository(User::class)->findOneBy(['telegramId' => $tgIdRaw]);
    }
}
