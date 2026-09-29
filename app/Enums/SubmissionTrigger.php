<?php

namespace App\Enums;

/** Qué originó un intento de envío. */
enum SubmissionTrigger: string
{
    case Issue = 'issue';
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
