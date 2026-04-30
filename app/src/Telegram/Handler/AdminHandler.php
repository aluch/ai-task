<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\Task;
use App\Entity\User;
use App\Service\AccessGate;
use App\Service\TelegramUserResolver;
use App\Enum\TaskStatus;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * /admin <subcommand> [args] — команды для админа (ID из ADMIN_TELEGRAM_ID).
 *
 *   /admin requests       — pending-запросы доступа с кнопками approve/reject
 *   /admin users          — все allowed users + count активных задач
 *   /admin invite <tg_id> — выдать доступ без запроса (друг сказал ID лично)
 *   /admin revoke <tg_id> — забрать доступ
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
            default => $this->cmdHelp($bot),
        };
    }

    private function cmdHelp(Nutgram $bot): void
    {
        $bot->sendMessage(text: <<<'MSG'
        🛠 Admin

        /admin requests — pending-запросы доступа
        /admin users — все allowed users + count задач
        /admin invite <tg_id> — выдать доступ без запроса
        /admin revoke <tg_id> — забрать доступ
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
}
