<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S1 — SaaS infra: subscriptions, payments, usage_counters + user.is_admin.
 *
 * Что делает:
 *  1. Создаёт subscriptions (план + статус + период) с индексом по
 *     (user_id, status) для быстрого lookup'а активной подписки.
 *  2. Создаёт payments (платежи, привязка к user + опц. subscription).
 *     amount_minor хранит копейки — единый стандарт для финансов.
 *  3. Создаёт usage_counters (по одному ряду на юзера, OneToOne через
 *     unique constraint на user_id) — для лимитов действий.
 *  4. Добавляет users.is_admin (bool default false).
 *  5. Bootstrap: telegram_id из env ADMIN_TELEGRAM_ID получает is_admin=true
 *     и is_allowed=true, чтобы AccessGate сразу нашёл админа в БД, а не в
 *     env. После этой миграции env используется только как маркер
 *     первичного админа (для повторных bootstrap'ов на свежей БД).
 */
final class Version20260507000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S1: subscriptions, payments, usage_counters + users.is_admin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE subscriptions (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                plan VARCHAR(16) NOT NULL,
                status VARCHAR(16) NOT NULL,
                trial_ends_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                current_period_start TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                current_period_end TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                cancelled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                external_subscription_id VARCHAR(128) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_subscriptions_user_status ON subscriptions (user_id, status)');

        $this->addSql(<<<'SQL'
            CREATE TABLE payments (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                subscription_id UUID DEFAULT NULL,
                amount_minor INTEGER NOT NULL,
                currency VARCHAR(3) NOT NULL,
                status VARCHAR(16) NOT NULL,
                external_payment_id VARCHAR(128) DEFAULT NULL,
                provider_data JSON DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                refunded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_payments_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_payments_subscription FOREIGN KEY (subscription_id)
                    REFERENCES subscriptions(id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE usage_counters (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                free_actions_count INTEGER DEFAULT 0 NOT NULL,
                free_period_start TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                pro_actions_count INTEGER DEFAULT 0 NOT NULL,
                pro_period_start TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT uniq_usage_counters_user UNIQUE (user_id),
                CONSTRAINT fk_usage_counters_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('ALTER TABLE users ADD is_admin BOOLEAN DEFAULT false NOT NULL');

        // Bootstrap: первый админ из env. Если ADMIN_TELEGRAM_ID не задан —
        // skip (например на чистой dev-машине без admin'а).
        $adminTg = (string) (getenv('ADMIN_TELEGRAM_ID') ?: ($_ENV['ADMIN_TELEGRAM_ID'] ?? ''));
        if ($adminTg !== '' && ctype_digit($adminTg)) {
            $this->addSql(
                'UPDATE users SET is_admin = true, is_allowed = true WHERE telegram_id = :tg',
                ['tg' => $adminTg],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP is_admin');
        $this->addSql('DROP TABLE usage_counters');
        $this->addSql('DROP TABLE payments');
        $this->addSql('DROP TABLE subscriptions');
    }
}
