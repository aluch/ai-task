<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whitelist в БД вместо env-переменной TELEGRAM_ALLOWED_USER_IDS.
 *
 * - Добавляем в users флаг is_allowed (default false) и таймстампы запроса
 *   и отклонения доступа.
 * - Существующих пользователей из старой env-переменной апгрейдим до
 *   is_allowed=true, чтобы не потерять им доступ при выкатке. Если в БД
 *   их ещё нет (юзер не писал боту, но был whitelist'ом) — UPSERT
 *   с минимальной заглушкой (имя из telegram_id, остальные поля пустые).
 *   Реальные данные подтянутся при первом /start.
 *
 * Источник списка ID: env TELEGRAM_ALLOWED_USER_IDS, comma-separated.
 * Парсим здесь же через getenv() — миграции выполняются в Symfony-runtime
 * с доступом к окружению.
 */
final class Version20260428000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Whitelist users in DB: is_allowed/access_requested_at/request_rejected_at + bootstrap from env';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD is_allowed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE users ADD access_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD request_rejected_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        // Частичный индекс — для админ-просмотра pending-запросов
        // (где их обычно единицы на тысячи allowed, full-index был бы переплатой).
        $this->addSql(
            'CREATE INDEX idx_users_pending_requests ON users (access_requested_at) '
            . 'WHERE is_allowed = false AND access_requested_at IS NOT NULL'
        );

        // Bootstrap: всех из старой env-переменной — в allowed.
        $envList = (string) (getenv('TELEGRAM_ALLOWED_USER_IDS') ?: ($_ENV['TELEGRAM_ALLOWED_USER_IDS'] ?? ''));
        $ids = array_filter(array_map('trim', explode(',', $envList)));

        foreach ($ids as $tgId) {
            if (!ctype_digit($tgId)) {
                continue;
            }
            // UPSERT: если пользователь уже писал боту — просто проставляем
            // is_allowed=true. Если нет — создаём минимальную запись с
            // UUID v7 и заглушкой имени.
            //
            // ON CONFLICT (telegram_id) — у нас telegram_id UNIQUE.
            $this->addSql(
                'INSERT INTO users (id, telegram_id, name, timezone, quiet_start_hour, quiet_end_hour, '
                . 'is_allowed, created_at) VALUES '
                . "(gen_random_uuid(), :tg, 'Admin (bootstrap)', 'Europe/Tallinn', 22, 8, true, NOW()) "
                . 'ON CONFLICT (telegram_id) DO UPDATE SET is_allowed = true',
                ['tg' => $tgId]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_users_pending_requests');
        $this->addSql('ALTER TABLE users DROP request_rejected_at');
        $this->addSql('ALTER TABLE users DROP access_requested_at');
        $this->addSql('ALTER TABLE users DROP is_allowed');
    }
}
