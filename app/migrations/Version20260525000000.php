<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renewal flow без auto-rebill: пользователь продлевает Pro через
 * /upgrade. За 3 дня и 1 день до истечения шлём напоминания, в момент
 * истечения — уведомление о переходе на Free.
 *
 * Поля нужны для дедупликации (повторный tick scheduler'а не должен
 * слать второе письмо):
 *   - notification_3d_renewal_sent_at — уведомление «через 3 дня»
 *   - notification_1d_renewal_sent_at — уведомление «завтра»
 *
 * notification_expired_sent_at (из S2 для триалов) переиспользуется
 * для paid-Pro: при activatePro обнуляем флаг для нового цикла.
 *
 * Это отдельный набор от триальных notification_3d_sent_at /
 * notification_1d_sent_at — те привязаны к trialEndsAt, эти — к
 * currentPeriodEnd платной подписки.
 */
final class Version20260525000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renewal: notification flags for 3d/1d before paid Pro expiry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions ADD notification_3d_renewal_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD notification_1d_renewal_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP notification_1d_renewal_sent_at');
        $this->addSql('ALTER TABLE subscriptions DROP notification_3d_renewal_sent_at');
    }
}
