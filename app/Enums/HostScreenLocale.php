<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Which language(s) the shared host screen shows. */
enum HostScreenLocale: string implements HasLabel
{
    case Ar = 'ar';
    case En = 'en';
    case Both = 'both';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ar => __('Arabic'),
            self::En => __('English'),
            self::Both => __('Arabic and English'),
        };
    }
}
