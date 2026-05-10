<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Маркерное сообщение: «прогони TrialNotifier». Диспатчится из
 * ReminderSchedule. Обрабатывается NotifyTrialEndingHandler.
 */
final class NotifyTrialEndingMessage
{
}
