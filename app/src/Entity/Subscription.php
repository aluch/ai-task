<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Subscription\Plan;
use App\Domain\Subscription\SubscriptionStatus;
use App\Entity\Trait\TimestampableTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Подписка пользователя. План + статус + период действия. Триал —
 * это Subscription с status=Trialing и trialEndsAt. Отменённая Pro —
 * status=Cancelled, доступ ещё работает до currentPeriodEnd.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscriptions')]
#[ORM\Index(name: 'idx_subscriptions_user_status', columns: ['user_id', 'status'])]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 16, enumType: Plan::class)]
    private Plan $plan;

    #[ORM\Column(type: 'string', length: 16, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $currentPeriodStart;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $externalSubscriptionId = null;

    public function __construct(
        User $user,
        Plan $plan,
        SubscriptionStatus $status,
        \DateTimeImmutable $currentPeriodStart,
        \DateTimeImmutable $currentPeriodEnd,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->plan = $plan;
        $this->status = $status;
        $this->currentPeriodStart = $currentPeriodStart;
        $this->currentPeriodEnd = $currentPeriodEnd;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function setPlan(Plan $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function setStatus(SubscriptionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $at): self
    {
        $this->trialEndsAt = $at;

        return $this;
    }

    public function getCurrentPeriodStart(): \DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function setCurrentPeriodStart(\DateTimeImmutable $at): self
    {
        $this->currentPeriodStart = $at;

        return $this;
    }

    public function getCurrentPeriodEnd(): \DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(\DateTimeImmutable $at): self
    {
        $this->currentPeriodEnd = $at;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $at): self
    {
        $this->cancelledAt = $at;

        return $this;
    }

    public function getExternalSubscriptionId(): ?string
    {
        return $this->externalSubscriptionId;
    }

    public function setExternalSubscriptionId(?string $id): self
    {
        $this->externalSubscriptionId = $id;

        return $this;
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }
}
