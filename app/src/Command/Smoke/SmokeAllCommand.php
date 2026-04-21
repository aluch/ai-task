<?php

declare(strict_types=1);

namespace App\Command\Smoke;

use App\Smoke\ScenarioRunner;
use App\Smoke\SmokeHarness;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:smoke:all',
    description: 'Прогнать все smoke-сценарии подряд. Exit code 0 если все passed, 1 если хоть один failed.',
)]
final class SmokeAllCommand extends Command
{
    public function __construct(
        private readonly ScenarioRunner $runner,
        private readonly SmokeHarness $harness,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Smoke scenarios');

        $failed = 0;
        $passed = 0;

        $names = $this->runner->names();
        foreach ($names as $i => $name) {
            $result = $this->runner->run($name);
            if ($result->passed) {
                $passed++;
                $io->writeln(sprintf('✅ %s (%.1fs)', $result->name, $result->elapsedSeconds));
            } else {
                $failed++;
                $io->writeln(sprintf('❌ %s (%.1fs): %s', $result->name, $result->elapsedSeconds, $result->message));
            }

            // Между assistant-сценариями ждём — иначе упираемся в 30k TPM
            // лимит Anthropic (Sonnet-вызовы тяжёлые). reminder-сценарии
            // API не дёргают, паузы не нужны.
            $nextName = $names[$i + 1] ?? null;
            $isAssistantNext = $nextName !== null && str_starts_with($nextName, 'assistant-');
            $isAssistantCurrent = str_starts_with($name, 'assistant-');
            if ($isAssistantCurrent && $isAssistantNext) {
                sleep(8);
            }
        }

        $io->newLine();
        $io->writeln("{$passed} passed, {$failed} failed");

        // Cleanup: после всего прогона оставлять мусор в БД не надо.
        $this->harness->reset();

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
