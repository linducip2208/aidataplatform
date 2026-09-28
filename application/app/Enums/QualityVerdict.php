<?php

namespace App\Enums;

enum QualityVerdict: string
{
    case Pass = 'pass';
    case Quarantine = 'quarantine';

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pass => 'badge-success',
            self::Quarantine => 'badge-danger',
        };
    }
}
