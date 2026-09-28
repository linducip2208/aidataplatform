<?php

namespace App\Enums;

enum DatasetStatus: string
{
    case Uploaded = 'uploaded';
    case Previewing = 'previewing';
    case Mapped = 'mapped';
    case Importing = 'importing';
    case Committed = 'committed';
    case Quarantined = 'quarantined';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Previewing => 'Previewing',
            self::Mapped => 'Mapped',
            self::Importing => 'Importing',
            self::Committed => 'Committed',
            self::Quarantined => 'Quarantined',
            self::Failed => 'Failed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Committed => 'badge-success',
            self::Quarantined, self::Failed => 'badge-danger',
            self::Importing, self::Previewing => 'badge-warning',
            default => 'badge-info',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Committed, self::Quarantined, self::Failed], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public static function tryFromName(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Uploaded;
    }
}
