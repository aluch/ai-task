<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TaskContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskContext>
 */
class TaskContextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskContext::class);
    }

    public function findByCode(string $code): ?TaskContext
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * @param string[] $codes
     * @return TaskContext[]
     */
    public function findByCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.code IN (:codes)')
            ->setParameter('codes', $codes)
            ->getQuery()
            ->getResult();
    }
}
