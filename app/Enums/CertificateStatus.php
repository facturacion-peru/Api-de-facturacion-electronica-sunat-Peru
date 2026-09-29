<?php

namespace App\Enums;

enum CertificateStatus: string
{
    case Current = 'current';
    case Replaced = 'replaced';
}
