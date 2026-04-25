<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampableTrait;
use App\Enum\TaskPriority;
use App\Enum\TaskSource;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'tasks')]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_tasks_user_status')]
#[ORM\Index(columns: ['deadline'], name: 'idx_tasks_deadline')]
#[ORM\HasLifecycleCallbacks]
class Task
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $estimatedMinutes = null;

    #[ORM\Column(type: 'string', length: 16, enumType: TaskPriority::class, options: ['default' => 'medium'])]
    private TaskPriority $priority = TaskPriority::MEDIUM;

    #[ORM\Column(type: 'string', length: 16, enumType: TaskStatus::class, options: ['default' => 'pending'])]
    private TaskStatus $status = TaskStatus::PENDING;

    #[ORM\Column(type: 'string', length: 16, enumType: TaskSource::class, options: ['default' => 'manual'])]
    private TaskSource $source = TaskSource::MANUAL;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceRef = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $reminderIntervalMinutes = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRemindedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $snoozedUntil = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $remindBeforeDeadlineMinutes = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deadlineReminderSentAt = null;

    /**
     * Учитывать ли quiet hours пользователя для напоминаний по этой задаче
     * (deadline + periodic + snooze wakeup). Когда пользователь ЯВНО выбирает
     * время («отложи до 6:50», «напомни за час»), ассистент ставит false —
     * спит пользователь или нет, он сам заказал этот момент.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $respectQuietHours = true;

    /**
     * Одноразовое напоминание на конкретный момент (Тип Г). Независимо от
     * дедлайна и периодических — отдельный таймер «в HH:MM напомнить про это».
     * После отправки пишется в $singleReminderSentAt и больше не срабатывает.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $singleReminderAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $singleReminderSentAt = null;

    /**
     * Отдельный флаг quiet hours для single reminder. Default false потому что
     * такой тип напоминаний всегда результат явного запроса пользователя
     * («напомни в 6:50» — значит в 6:50, а не в 8:00 когда кончатся quiet).
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $singleReminderRespectQuietHours = false;

    /** @var Collection<int, TaskContext> */
    #[ORM\ManyToMany(targetEntity: TaskContext::class)]
    #[ORM\JoinTable(name: 'task_context_link')]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'context_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $contexts;

    /** @var Collection<int, Task> Задачи, которые блокируют текущую */
    #[ORM\ManyToMany(targetEntity: Task::class, inversedBy: 'blocking')]
    #[ORM\JoinTable(name: 'task_dependencies')]
    #[ORM\JoinColumn(name: 'blocked_task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'blocker_task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $blockedBy;

    /** @var Collection<int, Task> Задачи, которые блокирует текущая */
    #[ORM\ManyToMany(targetEntity: Task::class, mappedBy: 'blockedBy')]
    private Collection $blocking;

    public function __construct(User $user, string $title)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->title = $title;
        $this->contexts = new ArrayCollection();
        $this->blockedBy = new ArrayCollection();
        $this->blocking = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getDeadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    public function setDeadline(?\DateTimeImmutable $deadline): self
    {
        $this->deadline = $deadline;

        return $this;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    public function setEstimatedMinutes(?int $estimatedMinutes): self
    {
        $this->estimatedMinutes = $estimatedMinutes;

        return $this;
    }

    public function getPriority(): TaskPriority
    {
        return $this->priority;
    }

    public function setPriority(TaskPriority $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    public function setStatus(TaskStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getSource(): TaskSource
    {
        return $this->source;
    }

    public function setSource(TaskSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getSourceRef(): ?string
    {
        return $this->sourceRef;
    }

    public function setSourceRef(?string $sourceRef): self
    {
        $this->sourceRef = $sourceRef;

        return $this;
    }

    public function getReminderIntervalMinutes(): ?int
    {
        return $this->reminderIntervalMinutes;
    }

    public function setReminderIntervalMinutes(?int $reminderIntervalMinutes): self
    {
        $this->reminderIntervalMinutes = $reminderIntervalMinutes;

        return $this;
    }

    public function getLastRemindedAt(): ?\DateTimeImmutable
    {
        return $this->lastRemindedAt;
    }

    public function setLastRemindedAt(?\DateTimeImmutable $lastRemindedAt): self
    {
        $this->lastRemindedAt = $lastRemindedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markDone(): self
    {
        $this->status = TaskStatus::DONE;
        $this->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    /**
     * Отменить задачу — задача больше не актуальна (в отличие от done,
     * которое означает «выполнено»). Используем то же поле completedAt
     * как «момент закрытия» для статистики/отчётов.
     */
    public function cancel(): self
    {
        $this->status = TaskStatus::CANCELLED;
        $this->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    public function getSnoozedUntil(): ?\DateTimeImmutable
    {
        return $this->snoozedUntil;
    }

    public function snooze(\DateTimeImmutable $until): self
    {
        $this->status = TaskStatus::SNOOZED;
        $this->snoozedUntil = $until;

        return $this;
    }

    public function reactivate(): self
    {
        $this->status = TaskStatus::PENDING;
        $this->snoozedUntil = null;

        return $this;
    }

    public function getRemindBeforeDeadlineMinutes(): ?int
    {
        return $this->remindBeforeDeadlineMinutes;
    }

    public function setRemindBeforeDeadlineMinutes(?int $minutes): self
    {
        $this->remindBeforeDeadlineMinutes = $minutes;

        return $this;
    }

    public function getDeadlineReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->deadlineReminderSentAt;
    }

    public function setDeadlineReminderSentAt(?\DateTimeImmutable $at): self
    {
        $this->deadlineReminderSentAt = $at;

        return $this;
    }

    public function markDeadlineReminderSent(): void
    {
        $this->deadlineReminderSentAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getRespectQuietHours(): bool
    {
        return $this->respectQuietHours;
    }

    public function setRespectQuietHours(bool $v): self
    {
        $this->respectQuietHours = $v;

        return $this;
    }

    public function getSingleReminderAt(): ?\DateTimeImmutable
    {
        return $this->singleReminderAt;
    }

    public function setSingleReminderAt(?\DateTimeImmutable $at): self
    {
        $this->singleReminderAt = $at;

        return $this;
    }

    public function getSingleReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->singleReminderSentAt;
    }

    public function setSingleReminderSentAt(?\DateTimeImmutable $at): self
    {
        $this->singleReminderSentAt = $at;

        return $this;
    }

    public function markSingleReminderSent(\DateTimeImmutable $at): self
    {
        $this->singleReminderSentAt = $at->setTimezone(new \DateTimeZone('UTC'));

        return $this;
    }

    public function getSingleReminderRespectQuietHours(): bool
    {
        return $this->singleReminderRespectQuietHours;
    }

    public function setSingleReminderRespectQuietHours(bool $v): self
    {
        $this->singleReminderRespectQuietHours = $v;

        return $this;
    }

    public function shouldSendSingleReminder(\DateTimeImmutable $now): bool
    {
        if ($this->singleReminderAt === null || $this->singleReminderSentAt !== null) {
            return false;
        }

        return $now >= $this->singleReminderAt;
    }

    public function shouldRemindBeforeDeadline(\DateTimeImmutable $now): bool
    {
        if ($this->deadline === null || $this->remindBeforeDeadlineMinutes === null) {
            return false;
        }
        if ($this->deadlineReminderSentAt !== null) {
            return false;
        }

        $triggerAt = $this->deadline->modify("-{$this->remindBeforeDeadlineMinutes} minutes");

        return $now >= $triggerAt;
    }

    /** @return Collection<int, TaskContext> */
    public function getContexts(): Collection
    {
        return $this->contexts;
    }

    public function addContext(TaskContext $context): self
    {
        if (!$this->contexts->contains($context)) {
            $this->contexts->add($context);
        }

        return $this;
    }

    public function removeContext(TaskContext $context): self
    {
        $this->contexts->removeElement($context);

        return $this;
    }

    /** @return Collection<int, Task> */
    public function getBlockedBy(): Collection
    {
        return $this->blockedBy;
    }

    public function addBlocker(Task $blocker): void
    {
        if ($blocker->getId()->equals($this->id)) {
            throw new \LogicException('Задача не может блокировать саму себя.');
        }

        if (!$this->blockedBy->contains($blocker)) {
            $this->blockedBy->add($blocker);
        }
    }

    public function removeBlocker(Task $blocker): void
    {
        $this->blockedBy->removeElement($blocker);
    }

    public function isBlocked(): bool
    {
        return $this->getActiveBlockers() !== [];
    }

    /**
     * @return Task[]
     */
    public function getActiveBlockers(): array
    {
        return $this->blockedBy->filter(
            fn (Task $t) => $t->getStatus() !== TaskStatus::DONE && $t->getStatus() !== TaskStatus::CANCELLED,
        )->toArray();
    }

    /**
     * Задачи, которые блокирует текущая (обратная сторона).
     *
     * @return Collection<int, Task>
     */
    public function getBlockedTasks(): Collection
    {
        return $this->blocking;
    }
}
