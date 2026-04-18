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
    description: 'Seed or update the base set of TaskContext rows (idempotent)',
)]
class SeedContextsCommand extends Command
{
    /** @var array<array{0: string, 1: string, 2: ?string}> */
    private const CONTEXTS = [
        ['at_home', 'Дома', null],
        ['outdoor', 'На улице / в дороге', null],
        ['at_dacha', 'На даче', null],
        ['at_office', 'На работе / в офисе', null],
        ['needs_internet', 'Нужен интернет', null],
        ['needs_phone_call', 'Требует звонка', null],
        ['quick', 'Короткая (до 15 минут)', null],
        ['focused', 'Требует концентрации', null],
        ['with_kids_ok', 'Можно делать с детьми', null],
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
        $updated = 0;
        $unchanged = 0;

        foreach (self::CONTEXTS as [$code, $label, $description]) {
            $existing = $this->contexts->findByCode($code);

            if ($existing === null) {
                $this->em->persist(new TaskContext($code, $label, $description));
                ++$created;
                continue;
            }

            $changed = false;
            if ($existing->getLabel() !== $label) {
                $existing->setLabel($label);
                $changed = true;
            }
            if ($existing->getDescription() !== $description) {
                $existing->setDescription($description);
                $changed = true;
            }

            if ($changed) {
                ++$updated;
            } else {
                ++$unchanged;
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            'Contexts: %d created, %d updated, %d unchanged.',
            $created,
            $updated,
            $unchanged,
        ));

        return Command::SUCCESS;
    }
}
