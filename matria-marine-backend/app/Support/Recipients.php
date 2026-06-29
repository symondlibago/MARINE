<?php

namespace App\Support;

class Recipients
{
    /**
     * Parse a possibly-messy email field into a list of valid addresses.
     *
     * Imported company data often crams several contacts into one field, e.g.
     * "info@cmigh.com / cssci@cmigh.com" or "a@x.com; b@y.com". Sending to the
     * raw string fails RFC validation, so we split on common separators
     * (slash, comma, semicolon, ampersand, whitespace) and keep the valid ones.
     *
     * @return string[] de-duplicated list of valid email addresses (may be empty)
     */
    public static function emails(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\s,;\/&]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $valid = array_filter(
            $parts,
            fn ($p) => filter_var($p, FILTER_VALIDATE_EMAIL) !== false
        );

        return array_values(array_unique($valid));
    }
}
