<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S4 — UNIQUE-индекс по payments.external_payment_id.
 *
 * Гарантия идемпотентности для successful_payment-callback'ов от
 * Telegram: при дубликате provider_payment_charge_id INSERT упадёт
 * с UniqueConstraintViolation, и в SuccessfulPaymentHandler мы
 * раньше отлавливаем дубль через findOneByExternalPaymentId — но
 * UNIQUE сверху защищает на случай гонки двух одновременных
 * callback'ов.
 *
 * Partial-индекс по WHERE external_payment_id IS NOT NULL — потому что
 * для не-Telegram платежей (например, ручной grant_pro) поле остаётся
 * NULL, а несколько NULL'ов в обычном UNIQUE-индексе Postgres трактует
 * как «уникальные», что корректно, но partial меньше + явнее.
 */
final class Version20260516000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'S4: UNIQUE index on payments.external_payment_id (idempotency)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_payments_external_payment_id '
            . 'ON payments (external_payment_id) '
            . 'WHERE external_payment_id IS NOT NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_payments_external_payment_id');
    }
}
