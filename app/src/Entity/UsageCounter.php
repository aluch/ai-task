<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Счётчик действий пользователя per-tariff. Free — скользящий 30-дневный
 * период от первого действия. Pro — billing period подписки.
 *
 * Логика сброса counter'ов в UsageTracker; entity — тупой holder.
 */
#[ORM\Entity]
#[ORM\Table(name: 'usage_counters')]
class UsageCounter
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', unique: true, nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $freeActionsCount = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $freePeriodStart = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $proActionsCount = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $proPeriodStart = null;

    public function __construct(User $user)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFreeActionsCount(): int
    {
        return $this->freeActionsCount;
    }

    public function setFreeActionsCount(int $n): self
    {
        $this->freeActionsCount = $n;

        return $this;
    }

    public function getFreePeriodStart(): ?\DateTimeImmutable
    {
        return $this->freePeriodStart;
    }

    public function setFreePeriodStart(?\DateTimeImmutable $at): self
    {
        $this->freePeriodStart = $at;

        return $this;
    }

    public function getProActionsCount(): int
    {
        return $this->proActionsCount;
    }

    public function setProActionsCount(int $n): self
    {
        $this->proActionsCount = $n;

        return $this;
    }

    public function getProPeriodStart(): ?\DateTimeImmutable
    {
        return $this->proPeriodStart;
    }

    public function setProPeriodStart(?\DateTimeImmutable $at): self
    {
        $this->proPeriodStart = $at;

        return $this;
    }
}
