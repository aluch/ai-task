<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\CreatedAtTrait;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User
{
    use CreatedAtTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'bigint', unique: true, nullable: true)]
    private ?string $telegramId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 64, options: ['default' => 'Europe/Tallinn'])]
    private string $timezone = 'Europe/Tallinn';

    #[ORM\Column(type: 'smallint', options: ['default' => 22])]
    private int $quietStartHour = 22;

    #[ORM\Column(type: 'smallint', options: ['default' => 8])]
    private int $quietEndHour = 8;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    /** @var Collection<int, Task> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Task::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $tasks;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->tasks = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTelegramId(): ?string
    {
        return $this->telegramId;
    }

    public function setTelegramId(?string $telegramId): self
    {
        $this->telegramId = $telegramId;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    /** @return Collection<int, Task> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function addTask(Task $task): self
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
            $task->setUser($this);
        }

        return $this;
    }

    public function removeTask(Task $task): self
    {
        $this->tasks->removeElement($task);

        return $this;
    }

    public function getQuietStartHour(): int
    {
        return $this->quietStartHour;
    }

    public function setQuietStartHour(int $hour): self
    {
        $this->quietStartHour = $hour;

        return $this;
    }

    public function getQuietEndHour(): int
    {
        return $this->quietEndHour;
    }

    public function setQuietEndHour(int $hour): self
    {
        $this->quietEndHour = $hour;

        return $this;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTimeImmutable $at): self
    {
        $this->lastMessageAt = $at;

        return $this;
    }

    /**
     * Попадает ли текущий момент (UTC) в тихие часы пользователя.
     * Интервал [quietStartHour, quietEndHour) в локальной зоне юзера.
     * Пересечение полуночи поддерживается (например 22→8).
     */
    public function isQuietHoursNow(\DateTimeImmutable $utcNow): bool
    {
        $userTz = new \DateTimeZone($this->timezone);
        $local = $utcNow->setTimezone($userTz);
        $hour = (int) $local->format('G');

        if ($this->quietStartHour === $this->quietEndHour) {
            return false;
        }

        if ($this->quietStartHour < $this->quietEndHour) {
            // Не пересекает полночь: например 1→5
            return $hour >= $this->quietStartHour && $hour < $this->quietEndHour;
        }

        // Пересекает полночь: например 22→8 → 22..23 или 0..7
        return $hour >= $this->quietStartHour || $hour < $this->quietEndHour;
    }

    /**
     * Писал ли юзер боту недавно (в пределах $withinMinutes минут).
     * Используется чтобы не напоминать, когда юзер и так в активном
     * диалоге — получит уведомление через несколько минут после паузы.
     */
    public function isRecentlyActive(\DateTimeImmutable $utcNow, int $withinMinutes = 5): bool
    {
        if ($this->lastMessageAt === null) {
            return false;
        }

        $diff = $utcNow->getTimestamp() - $this->lastMessageAt->getTimestamp();

        return $diff >= 0 && $diff <= $withinMinutes * 60;
    }
}
