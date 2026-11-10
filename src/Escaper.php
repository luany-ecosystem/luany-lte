<?php

namespace Luany\Lte;

/**
 * HTML escaping used by compiled templates.
 */
final class Escaper
{
    /** Flags for every HTML escape performed by LTE. */
    public const FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

    /**
     * Escape a value for HTML output.
     *
     *  - Htmlable values are returned as-is (trusted HTML);
     *  - null renders as an empty string;
     *  - everything else is cast to string and escaped with
     *    ENT_QUOTES | ENT_SUBSTITUTE (invalid UTF-8 is replaced with U+FFFD
     *    instead of producing an empty string).
     */
    public static function escape(mixed $value): string
    {
        if ($value instanceof Htmlable) {
            return $value->toHtml();
        }

        if ($value === null) {
            return '';
        }

        return htmlspecialchars((string) $value, self::FLAGS, 'UTF-8');
    }
}
