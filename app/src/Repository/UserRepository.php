<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByTelegramId(string|int $telegramId): ?User
    {
        return $this->findOneBy(['telegramId' => (string) $telegramId]);
    }

    public function findByIdentifier(string $identifier): ?User
    {
        if (Uuid::isValid($identifier)) {
            return $this->find(Uuid::fromString($identifier));
        }

        return $this->findByTelegramId($identifier);
    }
}
