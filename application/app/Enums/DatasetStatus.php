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

    /**
     * The machine-facing label. It stays English and equal to the backing
     * value so logs, API payloads and the enum contract test keep working.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The wording a user reads. Kept apart from label() because the views are
     * Indonesian while the values, the columns and this contract are English.
     */
    public function localizedLabel(): string
    {
        return match ($this) {
            self::Uploaded => 'Terunggah',
            self::Previewing => 'Sedang dipratinjau',
            self::Mapped => 'Dipetakan',
            self::Importing => 'Sedang diimpor',
            self::Committed => 'Dikomit',
            self::Quarantined => 'Dikarantina',
            self::Failed => 'Gagal',
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
