<?php

declare(strict_types=1);

namespace App\Notification;

enum SendResult: string
{
    case SENT = 'sent';
    case SKIPPED_QUIET_HOURS = 'skipped_quiet_hours';
    case SKIPPED_RECENTLY_ACTIVE = 'skipped_recently_active';
    case SKIPPED_NO_CHAT_ID = 'skipped_no_chat_id';
    case FAILED = 'failed';
}
