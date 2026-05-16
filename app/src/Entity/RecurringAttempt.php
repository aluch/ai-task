<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Subscription\RecurringAttemptStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Журнал попыток recurring-списания. Каждая попытка — отдельная запись;
 * первая попытка после периода жизни подписки + до 2-х retry с интервалом
 * 24 часа = до 3-х строк на одно продление.
 *
 * idempotenceKey передаётся ЮKassa в заголовке Idempotence-Key (UUID v4
 * на попытку) — защита от двойных списаний при retry'ях нашего HTTP-клиента.
 * externalPaymentId уникален (см. partial UNIQUE-индекс в миграции
 * Version20260520000000) — гарантирует, что webhook от ЮKassa не создаст
 * дубликат Payment даже если придёт дважды.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recurring_attempts')]
#[ORM\Index(name: 'idx_recurring_attempts_subscription', columns: ['subscription_id', 'attempt_number'])]
#[ORM\Index(name: 'idx_recurring_attempts_status_created', columns: ['status', 'created_at'])]
class RecurringAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Subscription $subscription;

    #[ORM\Column(type: 'smallint')]
    private int $attemptNumber;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $idempotenceKey;

    #[ORM\Column(type: 'integer')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 20, enumType: RecurringAttemptStatus::class)]
    private RecurringAttemptStatus $status;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $externalPaymentId = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorDescription = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        Subscription $subscription,
        int $attemptNumber,
        int $amountMinor,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->subscription = $subscription;
        $this->attemptNumber = $attemptNumber;
        $this->idempotenceKey = Uuid::v4();
        $this->amountMinor = $amountMinor;
        $this->status = RecurringAttemptStatus::Pending;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function getIdempotenceKey(): Uuid
    {
        return $this->idempotenceKey;
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getStatus(): RecurringAttemptStatus
    {
        return $this->status;
    }

    public function getExternalPaymentId(): ?string
    {
        return $this->externalPaymentId;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorDescription(): ?string
    {
        return $this->errorDescription;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markSucceeded(string $externalPaymentId, \DateTimeImmutable $at): void
    {
        $this->status = RecurringAttemptStatus::Succeeded;
        $this->externalPaymentId = $externalPaymentId;
        $this->completedAt = $at;
    }

    public function markFailed(?string $code, ?string $description, \DateTimeImmutable $at): void
    {
        $this->status = RecurringAttemptStatus::Failed;
        $this->errorCode = $code;
        $this->errorDescription = $description;
        $this->completedAt = $at;
    }
}
