<?php

declare(strict_types=1);

namespace App\Command\Smoke;

use App\Smoke\ScenarioRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:smoke:reminder-scenario',
    description: 'Прогнать один именованный сценарий reminder-пайплайна.',
)]
final class SmokeReminderScenarioCommand extends Command
{
    public function __construct(
        private readonly ScenarioRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Имя сценария: ' . implode(' | ', $this->knownNames()));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        if (!in_array($name, $this->knownNames(), true)) {
            $io->error("Unknown scenario '{$name}'. Known: " . implode(', ', $this->knownNames()));

            return Command::FAILURE;
        }

        $result = $this->runner->run($name);
        $line = sprintf(
            '%s %s (%.1fs)%s',
            $result->passed ? '✅' : '❌',
            $result->name,
            $result->elapsedSeconds,
            $result->passed ? '' : ': ' . $result->message,
        );
        $io->writeln($line);

        return $result->passed ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return string[] */
    private function knownNames(): array
    {
        return $this->runner->names();
    }
}
