<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\TaskContext;
use App\Repository\TaskContextRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed:contexts',
    description: 'Seed the base set of TaskContext rows (idempotent)',
)]
class SeedContextsCommand extends Command
{
    private const CONTEXTS = [
        ['at_home', 'Дома'],
        ['outdoor', 'На улице / в дороге'],
        ['at_dacha', 'На даче'],
        ['at_office', 'На работе / в офисе'],
        ['needs_internet', 'Нужен интернет'],
        ['needs_phone_call', 'Требует звонка'],
        ['quick', 'Короткая (до 15 минут)'],
        ['focused', 'Требует концентрации'],
        ['with_kids_ok', 'Можно делать с детьми'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskContextRepository $contexts,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $created = 0;
        $skipped = 0;

        foreach (self::CONTEXTS as [$code, $label]) {
            if ($this->contexts->findByCode($code) !== null) {
                ++$skipped;
                continue;
            }

            $this->em->persist(new TaskContext($code, $label));
            ++$created;
        }

        $this->em->flush();

        if ($created === 0) {
            $io->success(sprintf('%d contexts already exist, skipped.', $skipped));
        } else {
            $io->success(sprintf('Created %d contexts (%d already existed).', $created, $skipped));
        }

        return Command::SUCCESS;
    }
}
