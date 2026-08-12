<?php

declare(strict_types=1);

namespace Bot\Space\Memory;

/**
 * Deterministic host-side privacy gate shared by live and nightly memory writes.
 */
final class SpaceMemoryContentPolicy
{
    /** @return list<string> */
    public static function violations(string ...$values): array
    {
        $violations = [];
        foreach ($values as $value) {
            if (self::containsCredential($value)) {
                $violations[] = 'credential-like private data';
            }
            if (preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', $value) === 1) {
                $violations[] = 'email address';
            }
            if (preg_match(
                '/(?<![\pL\d])(?:HIV|AIDS|cancer|diabet(?:es|ic)|pregnan(?:t|cy)|patient[ _-]?id|medical[ _-]?record|diagnos(?:is|ed)|ВИЧ|СПИД|рак|диабет[\pL-]*|беремен[\pL-]*|диагноз[\pL-]*|медицинск[\pL-]*)(?![\pL\d])/iu',
                $value,
            ) === 1) {
                $violations[] = 'medical data';
            }
            if (self::containsPhoneNumber($value)) {
                $violations[] = 'phone number';
            }
            if (self::containsPaymentCard($value)) {
                $violations[] = 'payment-card data';
            }
        }

        return array_values(array_unique($violations));
    }

    public static function containsCredential(string $value): bool
    {
        return preg_match(
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----|\b(?:sk-[A-Za-z0-9_-]{16,}|AIza[0-9A-Za-z_-]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|xox[baprs]-[A-Za-z0-9-]{10,})\b|(?<![\pL\d])(?:password|passwd|pwd|secret|access[_ -]?token|api[_ -]?key|парол(?:ь|я)|секрет|токен|api[ _-]?ключ)\s*[:=]\s*["\']?[^\s"\']{6,}/iu',
            $value,
        ) === 1;
    }

    /** @param array<mixed> $value */
    public static function nestedStringsContainCredential(array $value): bool
    {
        foreach ($value as $item) {
            if ((is_string($item) && self::containsCredential($item))
                || (is_array($item) && self::nestedStringsContainCredential($item))
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<mixed> $value */
    public static function nestedStringsHaveViolations(array $value): bool
    {
        foreach ($value as $item) {
            if ((is_string($item) && self::violations($item) !== [])
                || (is_array($item) && self::nestedStringsHaveViolations($item))
            ) {
                return true;
            }
        }

        return false;
    }

    private static function containsPhoneNumber(string $value): bool
    {
        if (preg_match_all('/(?<![\pL\d])\+?(?:\d[\s().-]*){10,15}(?!\d)/u', $value, $phones) === 0) {
            return false;
        }
        foreach ($phones[0] as $phone) {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            if (strlen($digits) >= 10 && strlen($digits) <= 15) {
                return true;
            }
        }

        return false;
    }

    private static function containsPaymentCard(string $value): bool
    {
        if (preg_match_all('/(?<!\d)(?:\d[ -]?){13,19}(?!\d)/', $value, $cards) === 0) {
            return false;
        }
        foreach ($cards[0] as $card) {
            $digits = preg_replace('/\D+/', '', $card) ?? '';
            if (self::passesLuhn($digits)) {
                return true;
            }
        }

        return false;
    }

    private static function passesLuhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19 || preg_match('/^\d+$/D', $digits) !== 1) {
            return false;
        }

        $sum    = 0;
        $double = false;
        for ($index = strlen($digits) - 1; $index >= 0; --$index) {
            $digit = (int) $digits[$index];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
