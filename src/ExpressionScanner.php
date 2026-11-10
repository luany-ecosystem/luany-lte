<?php

namespace Luany\Lte;

/**
 * Minimal, string-aware scanner for PHP expressions embedded in templates.
 *
 * Tracks single/double-quoted strings (with backslash escapes) and the
 * nesting depth of (), [] and {} so that terminators and separators inside
 * strings or nested structures are ignored:
 *
 *   {{ '}}' }}                          → expression `'}}'`
 *   include args: 'x', ['a' => 1, 'b' => 2] → two arguments, not three
 *
 * Shared by the Parser (echo / directive argument boundaries) and the
 * Compiler (argument splitting).
 *
 * @internal
 */
final class ExpressionScanner
{
    /**
     * Find the offset of $terminator in $source at or after $offset, skipping
     * string literals and only matching at bracket depth 0.
     *
     * @return int|null  Offset of the terminator, or null if not found
     */
    public static function find(string $source, int $offset, string $terminator): ?int
    {
        $length = strlen($source);
        $tLen   = strlen($terminator);
        $depth  = 0;

        for ($i = $offset; $i < $length; $i++) {
            $ch = $source[$i];

            if ($ch === "'" || $ch === '"') {
                $i = self::skipString($source, $i);
                if ($i === null) {
                    return null;
                }
                continue;
            }

            if ($depth === 0 && substr_compare($source, $terminator, $i, $tLen) === 0) {
                return $i;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif (($ch === ')' || $ch === ']' || $ch === '}') && $depth > 0) {
                $depth--;
            }
        }

        return null;
    }

    /**
     * Split an argument list on top-level commas (outside strings and
     * brackets). Each part is trimmed; an empty/blank input yields [].
     *
     * @return list<string>
     */
    public static function split(?string $args): array
    {
        if ($args === null || trim($args) === '') {
            return [];
        }

        $parts  = [];
        $offset = 0;

        while (($comma = self::find($args, $offset, ',')) !== null) {
            $parts[] = trim(substr($args, $offset, $comma - $offset));
            $offset  = $comma + 1;
        }

        $parts[] = trim(substr($args, $offset));

        return $parts;
    }

    /**
     * Whether $expr is exactly one single- or double-quoted string literal.
     */
    public static function isStringLiteral(string $expr): bool
    {
        $expr = trim($expr);
        if ($expr === '' || ($expr[0] !== "'" && $expr[0] !== '"')) {
            return false;
        }

        return self::skipString($expr, 0) === strlen($expr) - 1;
    }

    /**
     * Given the offset of an opening quote, return the offset of the matching
     * closing quote, or null if the string is unterminated.
     */
    private static function skipString(string $source, int $start): ?int
    {
        $quote  = $source[$start];
        $length = strlen($source);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;
                continue;
            }
            if ($source[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }
}
