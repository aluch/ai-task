<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S5 — auto-rebill (recurring billing).
 *
 * Изменения в subscriptions:
 *   - saved_payment_method_id        — токен карты от ЮKassa после
 *                                      первого успешного платежа (через
 *                                      save_payment_method=true). NULL у
 *                                      админских grant'ов и платежей до S5.
 *   - auto_rebill_enabled            — пользователь может отключить
 *                                      автопродление (через UI). default true,
 *                                      чтобы старые active-подписки попали
 *                                      под рекуррент. У триалов / Free
 *                                      значения не имеет.
 *   - notification_24h_before_rebill_sent_at — дедуп уведомлений
 *                                      «через 24 часа спишется».
 *   - rebill_failed_attempts         — счётчик неудачных rebill'ов подряд.
 *                                      Сбрасывается на 0 при успехе. После 3 —
 *                                      подписка идёт на expire.
 *
 * Новая таблица recurring_attempts: журнал попыток списания. Каждая
 * попытка — отдельная запись (UUID v7, sort by created_at), со связкой
 * subscription_id → attempt_number → idempotence_key. external_payment_id
 * UNIQUE — гарантия идемпотентности webhook'ов от ЮKassa.
 */
final class Version20260520000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S5: recurring billing — saved payment method + attempts table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions ADD saved_payment_method_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD auto_rebill_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE subscriptions ADD notification_24h_before_rebill_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE subscriptions ADD rebill_failed_attempts SMALLINT NOT NULL DEFAULT 0');

        $this->addSql(<<<'SQL'
            CREATE TABLE recurring_attempts (
                id UUID NOT NULL,
                subscription_id UUID NOT NULL,
                attempt_number SMALLINT NOT NULL,
                idempotence_key UUID NOT NULL,
                amount_minor INTEGER NOT NULL,
                status VARCHAR(20) NOT NULL,
                external_payment_id VARCHAR(64) DEFAULT NULL,
                error_code VARCHAR(64) DEFAULT NULL,
                error_description TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_recurring_attempts_subscription FOREIGN KEY (subscription_id)
                    REFERENCES subscriptions(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_recurring_attempts_subscription ON recurring_attempts (subscription_id, attempt_number)');
        $this->addSql('CREATE INDEX idx_recurring_attempts_status_created ON recurring_attempts (status, created_at)');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_recurring_attempts_external_payment_id '
            . 'ON recurring_attempts (external_payment_id) '
            . 'WHERE external_payment_id IS NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recurring_attempts');
        $this->addSql('ALTER TABLE subscriptions DROP rebill_failed_attempts');
        $this->addSql('ALTER TABLE subscriptions DROP notification_24h_before_rebill_sent_at');
        $this->addSql('ALTER TABLE subscriptions DROP auto_rebill_enabled');
        $this->addSql('ALTER TABLE subscriptions DROP saved_payment_method_id');
    }
}
