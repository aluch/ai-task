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

    // name: явно — UnderscoreNamingStrategy не вставит подчёркивание между
    // буквой и цифрой («notification3d» → «notification3d», без _).
    /** Дедупликация уведомлений «триал заканчивается через 3 дня». */
    #[ORM\Column(name: 'notification_3d_sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notification3dSentAt = null;

    /** Дедупликация уведомлений «триал заканчивается через 1 день». */
    #[ORM\Column(name: 'notification_1d_sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notification1dSentAt = null;

    /** Дедупликация уведомлений «триал закончился». */
    #[ORM\Column(name: 'notification_expired_sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notificationExpiredSentAt = null;

    /**
     * Момент перехода триал → Pro (заполняется в activatePro,
     * если до этого подписка была trialing). Нужно для метрики
     * конверсии в /admin stats.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $convertedFromTrialAt = null;

    /**
     * Токен карты от ЮKassa (payment_method.id), сохранённый после
     * первого успешного платежа через save_payment_method=true.
     * Используется для recurring-списаний. NULL у админских grant'ов и
     * платежей до S5 (или если save_payment_method не прошёл).
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $savedPaymentMethodId = null;

    /**
     * Включено ли автопродление. По умолчанию true, пользователь может
     * отключить через /subscription. У триалов/cancelled значения не имеет.
     * False означает «доступ до currentPeriodEnd, дальше Free».
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $autoRebillEnabled = true;

    /**
     * Дедуп уведомления «через 24 часа спишется». Заполняется в
     * RebillScheduler при отправке.
     *
     * name: явно — иначе UnderscoreNamingStrategy не вставит подчёркивание
     * между буквой и цифрой (см. notification_3d_sent_at для аналогичного
     * случая в S2).
     */
    #[ORM\Column(name: 'notification_24h_before_rebill_sent_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notification24hBeforeRebillSentAt = null;

    /**
     * Сколько подряд неудачных rebill-attempt'ов было после последнего
     * успеха. Сбрасывается на 0 при успешном списании. После 3 — подписка
     * идёт на expire через RebillScheduler.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $rebillFailedAttempts = 0;

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

    public function getNotification3dSentAt(): ?\DateTimeImmutable
    {
        return $this->notification3dSentAt;
    }

    public function setNotification3dSentAt(?\DateTimeImmutable $at): self
    {
        $this->notification3dSentAt = $at;

        return $this;
    }

    public function getNotification1dSentAt(): ?\DateTimeImmutable
    {
        return $this->notification1dSentAt;
    }

    public function setNotification1dSentAt(?\DateTimeImmutable $at): self
    {
        $this->notification1dSentAt = $at;

        return $this;
    }

    public function getNotificationExpiredSentAt(): ?\DateTimeImmutable
    {
        return $this->notificationExpiredSentAt;
    }

    public function setNotificationExpiredSentAt(?\DateTimeImmutable $at): self
    {
        $this->notificationExpiredSentAt = $at;

        return $this;
    }

    public function getConvertedFromTrialAt(): ?\DateTimeImmutable
    {
        return $this->convertedFromTrialAt;
    }

    public function setConvertedFromTrialAt(?\DateTimeImmutable $at): self
    {
        $this->convertedFromTrialAt = $at;

        return $this;
    }

    public function getSavedPaymentMethodId(): ?string
    {
        return $this->savedPaymentMethodId;
    }

    public function setSavedPaymentMethodId(?string $id): self
    {
        $this->savedPaymentMethodId = $id;

        return $this;
    }

    public function isAutoRebillEnabled(): bool
    {
        return $this->autoRebillEnabled;
    }

    public function setAutoRebillEnabled(bool $enabled): self
    {
        $this->autoRebillEnabled = $enabled;

        return $this;
    }

    public function getNotification24hBeforeRebillSentAt(): ?\DateTimeImmutable
    {
        return $this->notification24hBeforeRebillSentAt;
    }

    public function setNotification24hBeforeRebillSentAt(?\DateTimeImmutable $at): self
    {
        $this->notification24hBeforeRebillSentAt = $at;

        return $this;
    }

    public function getRebillFailedAttempts(): int
    {
        return $this->rebillFailedAttempts;
    }

    public function setRebillFailedAttempts(int $n): self
    {
        $this->rebillFailedAttempts = $n;

        return $this;
    }
}
