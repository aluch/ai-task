<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:task:snooze',
    description: 'Snooze a task until a given moment (relative or absolute)',
)]
class TaskSnoozeCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskRepository $tasks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Task UUID')
            ->addArgument('until', InputArgument::REQUIRED, 'When to wake up: +2h, +1d, "tomorrow 9am", "2026-04-20 18:00"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $idRaw = (string) $input->getArgument('id');
        if (!Uuid::isValid($idRaw)) {
            $io->error('Argument <id> must be a valid UUID.');

            return Command::FAILURE;
        }

        $task = $this->tasks->find(Uuid::fromString($idRaw));
        if ($task === null) {
            $io->error(sprintf('Task %s not found.', $idRaw));

            return Command::FAILURE;
        }

        $userTz = new \DateTimeZone($task->getUser()->getTimezone());
        $rawUntil = (string) $input->getArgument('until');

        $until = $this->parseUntil($rawUntil, $userTz);
        if ($until === null) {
            $io->error(sprintf('Cannot parse <until>: %s', $rawUntil));

            return Command::FAILURE;
        }

        if ($until <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            $io->error('<until> must be in the future.');

            return Command::FAILURE;
        }

        $task->snooze($until);
        $this->em->flush();

        $localUntil = $until->setTimezone($userTz);
        $io->success(sprintf(
            'Task %s snoozed until %s (%s local / %s UTC).',
            $task->getId()->toRfc4122(),
            $localUntil->format('Y-m-d H:i'),
            $task->getUser()->getTimezone(),
            $until->format('Y-m-d H:i'),
        ));

        return Command::SUCCESS;
    }

    private const SHORTHAND_UNITS = [
        's' => 'seconds',
        'm' => 'minutes',
        'h' => 'hours',
        'd' => 'days',
        'w' => 'weeks',
    ];

    /**
     * Парсит относительные сдвиги (+2h, -30m, +1d, +2 hours) через modify() от now().
     * Абсолютные/именованные форматы (tomorrow 9am, 2026-04-20 18:00) интерпретирует
     * в зоне пользователя. Возвращает результат всегда в UTC.
     *
     * Короткие формы +2h/-30m раскрываются в +2 hours/-30 minutes до передачи в
     * modify(): иначе PHP парсит "+2h" как фиксированный офсет зоны +02:00 (синтаксис
     * TZ-офсета затмевает короткий relative-format). По той же причине "m" здесь
     * = minutes, не months — для месяцев пиши явно "+1 month".
     */
    private function parseUntil(string $raw, \DateTimeZone $userTz): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        try {
            if ($raw[0] === '+' || $raw[0] === '-') {
                $expanded = $this->expandShorthand($raw);
                $now = new \DateTimeImmutable('now', $utc);
                $modified = $now->modify($expanded);
                if ($modified === false || $modified->getTimestamp() === $now->getTimestamp()) {
                    return null;
                }

                return $modified;
            }

            return (new \DateTimeImmutable($raw, $userTz))->setTimezone($utc);
        } catch (\Exception) {
            return null;
        }
    }

    private function expandShorthand(string $raw): string
    {
        if (preg_match('/^([+-])(\d+)\s*([a-z])$/i', $raw, $m) === 1) {
            $unit = self::SHORTHAND_UNITS[strtolower($m[3])] ?? null;
            if ($unit !== null) {
                return $m[1] . $m[2] . ' ' . $unit;
            }
        }

        return $raw;
    }
}
