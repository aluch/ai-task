<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * S2 — backfill триала для существующих allowed-юзеров + три столбца
 * notification_*_sent_at в subscriptions для дедупликации e-mail-style
 * уведомлений «триал заканчивается через 3 дня / 1 день / закончился».
 *
 * Backfill даём тем, кто уже пользуется ботом (is_allowed=true), чтобы
 * не наказать их за «они зашли до S2». Админам (is_admin=true) — нет:
 * они безлимитны и подписки им не нужны вовсе. Если у юзера уже есть
 * subscription (теоретически невозможно до S2, но защита) — пропускаем.
 *
 * Идемпотентна: повторный прогон ничего не сделает (WHERE s.id IS NULL
 * уже не вернёт никого).
 */
final class Version20260510000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S2: trial backfill + subscriptions.notification_*_sent_at';
    }

    public function up(Schema $schema): void
    {
        // Три флага «уже отправлено» для каждого окна уведомления.
        // Storage: TIMESTAMPTZ (когда отправлено) + IS NOT NULL = «уже было».
        $this->addSql('ALTER TABLE subscriptions ADD notification_3d_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD notification_1d_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD notification_expired_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        // Backfill триала. Не трогаем админов и тех, у кого уже есть запись.
        // SubscriptionService::startTrial недоступен в миграции (нет контейнера),
        // поэтому вручную через DBAL.
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
        $trialEnd = (new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id FROM users u
             LEFT JOIN subscriptions s ON s.user_id = u.id
             WHERE u.is_allowed = true
               AND u.is_admin = false
               AND s.id IS NULL',
        );

        foreach ($rows as $row) {
            $userId = $row['id'];
            $subscriptionId = Uuid::v7()->toRfc4122();

            $this->connection->executeStatement(
                'INSERT INTO subscriptions
                    (id, user_id, plan, status, trial_ends_at,
                     current_period_start, current_period_end, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $subscriptionId,
                    $userId,
                    'pro',
                    'trialing',
                    $trialEnd,
                    $now,
                    $trialEnd,
                    $now,
                    $now,
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Откат backfill'а — снять только trialing-подписки, созданные этой
        // миграцией. Простой признак: status=trialing + trial_ends_at заполнен.
        // На практике downgrade S2 не запустишь без потери данных пользователя,
        // поэтому ограничимся снятием 3 столбцов.
        $this->addSql('ALTER TABLE subscriptions DROP notification_3d_sent_at');
        $this->addSql('ALTER TABLE subscriptions DROP notification_1d_sent_at');
        $this->addSql('ALTER TABLE subscriptions DROP notification_expired_sent_at');
    }
}
