<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Analyst = 'analyst';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Analyst => 'Analyst',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full access, model governance approval, user management.',
            self::Analyst => 'Upload datasets, run quality checks, train models, use the assistant.',
            self::Viewer => 'Read-only dashboards and reports.',
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
