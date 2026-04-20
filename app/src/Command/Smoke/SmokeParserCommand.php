<?php

declare(strict_types=1);

namespace App\Command\Smoke;

use App\AI\TaskParser;
use App\Smoke\SmokeHarness;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:smoke:parser',
    description: 'Прогнать TaskParser::parse на заданном тексте и вывести DTO без сохранения в БД.',
)]
final class SmokeParserCommand extends Command
{
    public function __construct(
        private readonly SmokeHarness $harness,
        private readonly TaskParser $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('text', InputArgument::REQUIRED, 'Текст для парсинга')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Зафиксировать текущее время.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $text = (string) $input->getArgument('text');

        $user = $this->harness->ensureTestUser();

        $nowOpt = $input->getOption('now');
        $now = $nowOpt
            ? new \DateTimeImmutable((string) $nowOpt)
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $dto = $this->parser->parse($text, $user, $now);

        $io->writeln('<info>title:</info> ' . $dto->title);
        $io->writeln('<info>description:</info> ' . ($dto->description ?? 'null'));
        $io->writeln('<info>deadline (UTC):</info> ' . ($dto->deadline?->format('c') ?? 'null'));
        $io->writeln('<info>estimatedMinutes:</info> ' . ($dto->estimatedMinutes ?? 'null'));
        $io->writeln('<info>priority:</info> ' . $dto->priority->value);
        $io->writeln('<info>contextCodes:</info> ' . ($dto->contextCodes === [] ? '[]' : implode(', ', $dto->contextCodes)));
        $io->writeln('<info>remindBeforeDeadlineMinutes:</info> ' . ($dto->remindBeforeDeadlineMinutes ?? 'null'));
        $io->writeln('<info>reminderIntervalMinutes:</info> ' . ($dto->reminderIntervalMinutes ?? 'null'));
        $io->writeln('<info>parserNotes:</info> ' . ($dto->parserNotes ?? 'null'));

        return Command::SUCCESS;
    }
}
