<?php

namespace App\Enums;

/** Which language(s) the shared host screen shows. */
enum HostScreenLocale: string
{
    case Ar = 'ar';
    case En = 'en';
    case Both = 'both';
}
