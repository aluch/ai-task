<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Subscription\PaymentStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'payments')]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Subscription $subscription = null;

    #[ORM\Column(type: 'integer')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'RUB';

    #[ORM\Column(type: 'string', length: 16, enumType: PaymentStatus::class)]
    private PaymentStatus $status;

    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    private ?string $externalPaymentId = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $providerData = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    public function __construct(User $user, int $amountMinor, PaymentStatus $status, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->amountMinor = $amountMinor;
        $this->status = $status;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $s): self
    {
        $this->subscription = $s;

        return $this;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $s): self
    {
        $this->status = $s;

        return $this;
    }

    public function getExternalPaymentId(): ?string
    {
        return $this->externalPaymentId;
    }

    public function setExternalPaymentId(?string $id): self
    {
        $this->externalPaymentId = $id;

        return $this;
    }

    public function getProviderData(): ?array
    {
        return $this->providerData;
    }

    public function setProviderData(?array $data): self
    {
        $this->providerData = $data;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRefundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function setRefundedAt(?\DateTimeImmutable $at): self
    {
        $this->refundedAt = $at;

        return $this;
    }
}
