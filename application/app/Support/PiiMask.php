<?php

namespace App\Support;

/**
 * Minimal PII masking for logs, audit details and exports.
 *
 * Pure string helpers — no I/O, no config, no behaviour change anywhere they
 * are adopted. Adoption status: used today only by
 * `App\Http\Middleware\EnsureRole` (actor email in the denial log). Wider
 * adoption (audit `detail` blobs, CSV exports, assistant evidence) is
 * documented in `docs/security.md` §7 and left to the owning agents, so this
 * class must stay dependency-free.
 */
class PiiMask
{
    /**
     * Mask an email address, keeping the domain for operability.
     *
     * `budi.santoso@example.co.id` → `b***@example.co.id`.
     */
    public static function maskEmail(string $email): string
    {
        $email = trim($email);

        if (! str_contains($email, '@')) {
            return $email === '' ? '' : '***';
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($local === '' || $domain === '') {
            return '***';
        }

        return mb_substr($local, 0, 1).'***@'.$domain;
    }

    /**
     * Mask a phone number, keeping the last two digits for correlation.
     * `0812-3456-7890` → `***-***-**90`.
     */
    public static function maskPhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return $phone;
        }

        if (strlen($digits) <= 2) {
            return '***';
        }

        $masked = str_repeat('*', strlen($digits) - 2).substr($digits, -2);

        // Preserve the caller's grouping when it looks like a phone number;
        // otherwise return the flat masked digits.
        $separators = (int) preg_match_all('/[\s\-.()]/', $phone);

        if ($separators === 0) {
            return $masked;
        }

        $out = '';
        $idx = 0;

        for ($i = 0, $len = strlen($phone); $i < $len; $i++) {
            $out .= ctype_digit($phone[$i]) ? ($masked[$idx++] ?? '*') : $phone[$i];
        }

        return $out;
    }

    /**
     * Mask every email address and phone-like run inside free text.
     */
    public static function maskText(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            static fn (array $m): string => self::maskEmail($m[0]),
            $text
        );

        // 8+ digit runs with optional separators: long enough to be a phone,
        // short enough to skip years, ids and row counts in most log lines.
        return (string) preg_replace_callback(
            '/\+?(?:\d[\s\-.()]?){8,16}/',
            static fn (array $m): string => self::maskPhone($m[0]),
            $text
        );
    }

    /**
     * Recursively mask PII in a structured payload (audit `detail`, export
     * rows). Keys named like email/phone are masked wholesale; other strings
     * are passed through `maskText()`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function maskArray(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::maskArray($value);

                continue;
            }

            if (! is_string($value)) {
                $out[$key] = $value;

                continue;
            }

            $name = strtolower((string) $key);

            if (str_contains($name, 'email')) {
                $out[$key] = self::maskEmail($value);
            } elseif (str_contains($name, 'phone') || str_contains($name, 'telepon') || str_contains($name, 'telp')) {
                $out[$key] = self::maskPhone($value);
            } else {
                $out[$key] = self::maskText($value);
            }
        }

        return $out;
    }
}
