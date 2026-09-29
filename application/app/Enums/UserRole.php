<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Analyst = 'analyst';
    case Viewer = 'viewer';

    /**
     * The machine-facing label. It is part of the public role contract
     * (`tests/Unit/UserRoleTest.php` pins the three strings), so it stays
     * English even though the interface is Indonesian.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Analyst => 'Analyst',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * The wording a user reads next to a role badge or in the role picker.
     */
    public function localizedLabel(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Analyst => 'Analis Data',
            self::Viewer => 'Pengunjung',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Akses penuh, persetujuan tata kelola model, dan pengelolaan pengguna.',
            self::Analyst => 'Unggah dataset, jalankan pemeriksaan kualitas, latih model, dan gunakan asisten.',
            self::Viewer => 'Hanya dapat membuka dasbor dan laporan tanpa mengubah data.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    public static function tryFromName(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Viewer;
    }
}
