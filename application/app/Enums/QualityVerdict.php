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

    /**
     * The wording a user reads. The enum is the only place this is written:
     * the dataset list and the quality page both render it, and neither keeps
     * a private copy of the map.
     */
    public function localizedLabel(): string
    {
        return match ($this) {
            self::Pass => 'Lolos',
            self::Quarantine => 'Dikarantina',
        };
    }
}
