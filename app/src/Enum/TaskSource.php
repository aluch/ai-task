<?php

declare(strict_types=1);

namespace App\Enum;

enum TaskSource: string
{
    case MANUAL = 'manual';
    case AI_PARSED = 'ai_parsed';
    case VK = 'vk';
    case TELEGRAM = 'telegram';
}
